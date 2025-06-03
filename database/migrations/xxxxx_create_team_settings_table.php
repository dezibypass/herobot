<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('team_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->timestamps();
            
            $table->unique(['team_id', 'key']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('team_settings');
    }
};

// app/Models/TeamSetting.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class TeamSetting extends Model
{
    protected $fillable = ['team_id', 'key', 'value', 'encrypted'];

    protected $casts = [
        'encrypted' => 'boolean',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function getValueAttribute($value)
    {
        if ($this->encrypted && $value) {
            return Crypt::decryptString($value);
        }
        return $value;
    }

    public function setValueAttribute($value)
    {
        if ($this->encrypted && $value) {
            $this->attributes['value'] = Crypt::encryptString($value);
        } else {
            $this->attributes['value'] = $value;
        }
    }
}

// app/Models/Team.php - Add this method
public function settings()
{
    return $this->hasMany(TeamSetting::class);
}

public function getSetting($key, $default = null)
{
    $setting = $this->settings()->where('key', $key)->first();
    return $setting ? $setting->value : $default;
}

public function setSetting($key, $value, $encrypted = false)
{
    return $this->settings()->updateOrCreate(
        ['key' => $key],
        ['value' => $value, 'encrypted' => $encrypted]
    );
}

// app/Services/TeamAIService.php
namespace App\Services;

use App\Models\Team;
use Illuminate\Support\Facades\Http;

class TeamAIService
{
    protected $team;

    public function __construct(Team $team)
    {
        $this->team = $team;
    }

    public function getAIConfiguration()
    {
        return [
            'provider' => $this->team->getSetting('ai_provider', 'openrouter'),
            'base_url' => $this->team->getSetting('ai_base_url', 'https://openrouter.ai/api/v1'),
            'api_key' => $this->team->getSetting('ai_api_key'),
            'model' => $this->team->getSetting('ai_model', 'google/gemini-flash-1.5-8b'),
            'embedding_provider' => $this->team->getSetting('embedding_provider', 'openai'),
            'embedding_model' => $this->team->getSetting('embedding_model', 'text-embedding-3-small'),
            'embedding_api_key' => $this->team->getSetting('embedding_api_key'),
        ];
    }

    public function generateResponse($messages, $maxTokens = 2000)
    {
        $config = $this->getAIConfiguration();
        
        if (!$config['api_key']) {
            throw new \Exception('AI API key not configured for this team');
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $config['api_key'],
            'Content-Type' => 'application/json',
            'HTTP-Referer' => config('app.url'),
            'X-Title' => config('app.name'),
        ])->post($config['base_url'] . '/chat/completions', [
            'model' => $config['model'],
            'messages' => $messages,
            'max_tokens' => $maxTokens,
        ]);

        if (!$response->successful()) {
            throw new \Exception('AI API request failed: ' . $response->body());
        }

        return $response->json()['choices'][0]['message']['content'];
    }

    public function createEmbedding($text)
    {
        $config = $this->getAIConfiguration();
        
        switch ($config['embedding_provider']) {
            case 'openai':
                return $this->createOpenAIEmbedding($text, $config);
            case 'gemini':
                return $this->createGeminiEmbedding($text, $config);
            default:
                throw new \Exception('Unsupported embedding provider: ' . $config['embedding_provider']);
        }
    }

    private function createOpenAIEmbedding($text, $config)
    {
        $apiKey = $config['embedding_api_key'] ?: $config['api_key'];
        
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/embeddings', [
            'model' => $config['embedding_model'],
            'input' => $text,
        ]);

        if (!$response->successful()) {
            throw new \Exception('OpenAI embedding request failed: ' . $response->body());
        }

        return $response->json()['data'][0]['embedding'];
    }

    private function createGeminiEmbedding($text, $config)
    {
        $apiKey = $config['embedding_api_key'] ?: $config['api_key'];
        
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post("https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:embedContent?key={$apiKey}", [
            'model' => 'models/text-embedding-004',
            'content' => [
                'parts' => [['text' => $text]]
            ]
        ]);

        if (!$response->successful()) {
            throw new \Exception('Gemini embedding request failed: ' . $response->body());
        }

        return $response->json()['embedding']['values'];
    }
}

// app/Http/Controllers/TeamSettingsController.php
namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\Request;

class TeamSettingsController extends Controller
{
    public function aiSettings(Team $team)
    {
        $this->authorize('update', $team);
        
        return inertia('Teams/AISettings', [
            'team' => $team,
            'settings' => [
                'ai_provider' => $team->getSetting('ai_provider', 'openrouter'),
                'ai_base_url' => $team->getSetting('ai_base_url', 'https://openrouter.ai/api/v1'),
                'ai_model' => $team->getSetting('ai_model', 'google/gemini-flash-1.5-8b'),
                'embedding_provider' => $team->getSetting('embedding_provider', 'openai'),
                'embedding_model' => $team->getSetting('embedding_model', 'text-embedding-3-small'),
                'has_ai_key' => !empty($team->getSetting('ai_api_key')),
                'has_embedding_key' => !empty($team->getSetting('embedding_api_key')),
            ],
        ]);
    }

    public function updateAISettings(Request $request, Team $team)
    {
        $this->authorize('update', $team);
        
        $validated = $request->validate([
            'ai_provider' => 'required|string|in:openrouter,openai,anthropic,custom',
            'ai_base_url' => 'required|url',
            'ai_api_key' => 'nullable|string',
            'ai_model' => 'required|string',
            'embedding_provider' => 'required|string|in:openai,gemini',
            'embedding_model' => 'required|string',
            'embedding_api_key' => 'nullable|string',
        ]);

        // Save settings
        foreach ($validated as $key => $value) {
            if (str_contains($key, 'api_key')) {
                if ($value) {
                    $team->setSetting($key, $value, true); // Encrypted
                }
            } else {
                $team->setSetting($key, $value);
            }
        }

        return back()->with('success', 'AI settings updated successfully.');
    }

    public function testAIConnection(Request $request, Team $team)
    {
        $this->authorize('update', $team);
        
        try {
            $aiService = new \App\Services\TeamAIService($team);
            
            $response = $aiService->generateResponse([
                ['role' => 'user', 'content' => 'Say "Hello, this is a test!" if you can read this message.']
            ]);
            
            return response()->json([
                'success' => true,
                'response' => $response,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}

// Update app/Http/Controllers/WhatsAppMessageController.php to use team settings
private function generateResponse($bot, $message, $chatHistory)
{
    $team = $bot->team;
    $aiService = new \App\Services\TeamAIService($team);
    
    // Get knowledge using team's embedding configuration
    $relevantKnowledge = $this->searchSimilarKnowledge($message, $bot, $team);
    
    // Build system prompt
    $systemPrompt = $bot->prompt;
    if ($relevantKnowledge->isNotEmpty()) {
        $systemPrompt .= "\n\nRelevant knowledge:\n";
        foreach ($relevantKnowledge as $knowledge) {
            $systemPrompt .= "{$knowledge['text']}\n\n";
        }
    }

    // Build messages array
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ...$chatHistory->map(function ($ch) {
            return [
                ['role' => 'user', 'content' => $ch->message],
                ['role' => 'assistant', 'content' => $ch->response]
            ];
        })->flatten(1)->toArray(),
        ['role' => 'user', 'content' => $message]
    ];

    try {
        $response = $aiService->generateResponse($messages);
        return $this->convertMarkdownToWhatsApp($response);
    } catch (\Exception $e) {
        Log::error('Failed to generate response using team AI service: ' . $e->getMessage());
        return 'Sorry, I encountered an error. Please try again.';
    }
}

private function searchSimilarKnowledge($query, $bot, $team)
{
    try {
        $aiService = new \App\Services\TeamAIService($team);
        $queryEmbedding = $aiService->createEmbedding($query);

        $knowledgeVectors = $bot->knowledge()
            ->where('status', 'completed')
            ->with(['vectors:id,knowledge_id,text,vector'])
            ->get()
            ->flatMap(function ($knowledge) use ($queryEmbedding) {
                return $knowledge->vectors->map(function ($vector) use ($queryEmbedding) {
                    return [
                        'text' => $vector->text,
                        'similarity' => $this->calculateSimilarity($queryEmbedding, $vector->vector),
                    ];
                });
            });

        return $knowledgeVectors->sortByDesc('similarity')->take(3)->values();
    } catch (\Exception $e) {
        Log::error('Error searching similar knowledge with team settings: ' . $e->getMessage());
        return collect();
    }
}
