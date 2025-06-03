<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->onDelete('cascade');
            $table->string('sender_id'); // phone number, telegram chat id, etc
            $table->string('sender_name')->nullable();
            $table->string('sender_type')->default('user'); // user, group, channel
            $table->enum('status', ['active', 'escalated', 'resolved', 'archived'])->default('active');
            $table->foreignId('agent_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('last_message_at')->nullable();
            $table->integer('message_count')->default(0);
            $table->json('metadata')->nullable(); // platform-specific data
            $table->timestamps();

            $table->index(['integration_id', 'sender_id']);
            $table->index(['status', 'last_message_at']);
        });

        // Update chat_histories table to link to sessions
        Schema::table('chat_histories', function (Blueprint $table) {
            $table->foreignId('chat_session_id')->nullable()->constrained()->onDelete('cascade');
            $table->index('chat_session_id');
        });
    }

    public function down()
    {
        Schema::table('chat_histories', function (Blueprint $table) {
            $table->dropForeign(['chat_session_id']);
            $table->dropColumn('chat_session_id');
        });
        
        Schema::dropIfExists('chat_sessions');
    }
};

// app/Models/ChatSession.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSession extends Model
{
    protected $fillable = [
        'integration_id',
        'sender_id',
        'sender_name',
        'sender_type',
        'status',
        'agent_id',
        'last_message_at',
        'message_count',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_message_at' => 'datetime',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatHistory::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatHistory::class)->latestOfMany();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeEscalated($query)
    {
        return $query->where('status', 'escalated');
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->whereHas('integration', function ($q) use ($teamId) {
            $q->where('team_id', $teamId);
        });
    }

    public function getFormattedSenderIdAttribute()
    {
        switch ($this->integration->type) {
            case 'whatsapp':
                return $this->formatPhoneNumber($this->sender_id);
            case 'telegram':
                return '@' . ($this->sender_name ?: $this->sender_id);
            default:
                return $this->sender_id;
        }
    }

    private function formatPhoneNumber($phone)
    {
        // Remove non-numeric characters
        $clean = preg_replace('/[^0-9]/', '', $phone);
        
        // Format based on length
        if (strlen($clean) === 12 && substr($clean, 0, 2) === '62') {
            return '+62 ' . substr($clean, 2, 3) . '-' . substr($clean, 5, 4) . '-' . substr($clean, 9);
        }
        
        return '+' . $clean;
    }
}

// app/Http/Controllers/ChatController.php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\ChatHistory;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $team = $request->user()->currentTeam;
        
        $query = ChatSession::forTeam($team->id)
            ->with(['integration', 'agent', 'latestMessage'])
            ->orderBy('last_message_at', 'desc');

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('integration')) {
            $query->where('integration_id', $request->integration);
        }

        if ($request->filled('agent')) {
            $query->where('agent_id', $request->agent);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('sender_id', 'like', "%{$search}%")
                  ->orWhere('sender_name', 'like', "%{$search}%")
                  ->orWhereHas('messages', function ($mq) use ($search) {
                      $mq->where('message', 'like', "%{$search}%")
                        ->orWhere('response', 'like', "%{$search}%");
                  });
            });
        }

        $sessions = $query->paginate(20)->withQueryString();

        // Get summary stats
        $stats = $this->getChatStats($team->id);

        // Get available integrations for filter
        $integrations = Integration::where('team_id', $team->id)
            ->select('id', 'name', 'type')
            ->get();

        return inertia('Chats/Index', [
            'sessions' => $sessions,
            'stats' => $stats,
            'integrations' => $integrations,
            'filters' => $request->only(['status', 'integration', 'agent', 'search']),
        ]);
    }

    public function show(Request $request, ChatSession $session)
    {
        $this->authorize('view', $session);

        $session->load(['integration', 'agent']);
        
        $messages = $session->messages()
            ->orderBy('created_at', 'asc')
            ->paginate(50);

        return inertia('Chats/Show', [
            'session' => $session,
            'messages' => $messages,
        ]);
    }

    public function escalate(Request $request, ChatSession $session)
    {
        $this->authorize('update', $session);

        $validated = $request->validate([
            'agent_id' => 'required|exists:users,id',
            'note' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($session, $validated) {
            $session->update([
                'status' => 'escalated',
                'agent_id' => $validated['agent_id'],
            ]);

            // Add system message about escalation
            ChatHistory::create([
                'chat_session_id' => $session->id,
                'integration_id' => $session->integration_id,
                'sender' => 'system',
                'message' => "[System] Chat escalated to agent: {$validated['agent_id']}" . 
                           ($validated['note'] ? " - Note: {$validated['note']}" : ""),
                'response' => '',
            ]);
        });

        return back()->with('success', 'Chat escalated successfully.');
    }

    public function resolve(Request $request, ChatSession $session)
    {
        $this->authorize('update', $session);

        $session->update(['status' => 'resolved']);

        return back()->with('success', 'Chat marked as resolved.');
    }

    public function archive(Request $request, ChatSession $session)
    {
        $this->authorize('update', $session);

        $session->update(['status' => 'archived']);

        return back()->with('success', 'Chat archived successfully.');
    }

    private function getChatStats($teamId)
    {
        $baseQuery = ChatSession::forTeam($teamId);

        return [
            'total' => $baseQuery->count(),
            'active' => $baseQuery->where('status', 'active')->count(),
            'escalated' => $baseQuery->where('status', 'escalated')->count(),
            'today' => $baseQuery->whereDate('created_at', today())->count(),
            'this_week' => $baseQuery->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
        ];
    }
}

// app/Services/ChatSessionService.php

namespace App\Services;

use App\Models\ChatSession;
use App\Models\ChatHistory;
use App\Models\Integration;

class ChatSessionService
{
    public function findOrCreateSession(Integration $integration, string $senderId, array $metadata = [])
    {
        $session = ChatSession::where('integration_id', $integration->id)
            ->where('sender_id', $senderId)
            ->first();

        if (!$session) {
            $session = ChatSession::create([
                'integration_id' => $integration->id,
                'sender_id' => $senderId,
                'sender_name' => $metadata['sender_name'] ?? null,
                'sender_type' => $metadata['sender_type'] ?? 'user',
                'status' => 'active',
                'last_message_at' => now(),
                'message_count' => 0,
                'metadata' => $metadata,
            ]);
        }

        return $session;
    }

    public function addMessage(ChatSession $session, string $message, string $response, string $sender = null)
    {
        $chatHistory = ChatHistory::create([
            'chat_session_id' => $session->id,
            'integration_id' => $session->integration_id,
            'sender' => $sender ?: $session->sender_id,
            'message' => $message,
            'response' => $response,
        ]);

        // Update session stats
        $session->increment('message_count');
        $session->update(['last_message_at' => now()]);

        return $chatHistory;
    }

    public function escalateToAgent(ChatSession $session, int $agentId, string $note = null)
    {
        $session->update([
            'status' => 'escalated',
            'agent_id' => $agentId,
        ]);

        // Add system message
        $this->addMessage(
            $session,
            '[System] Chat escalated to agent' . ($note ? " - {$note}" : ''),
            '',
            'system'
        );

        // TODO: Notify agent (email, websocket, etc.)
        
        return $session;
    }

    public function getSessionSummary(ChatSession $session)
    {
        $messages = $session->messages()
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $summary = [
            'total_messages' => $session->message_count,
            'duration' => $session->created_at->diffForHumans($session->last_message_at),
            'platform' => $session->integration->type,
            'recent_messages' => $messages->reverse()->values(),
        ];

        return $summary;
    }
}
