<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->string('telegram_bot_token')->nullable();
            $table->string('telegram_username')->nullable();
            $table->string('webhook_url')->nullable();
            $table->json('settings')->nullable();
        });
    }

    public function down()
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn(['telegram_bot_token', 'telegram_username', 'webhook_url', 'settings']);
        });
    }
};

// app/Http/Controllers/TelegramController.php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Services\TelegramBotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    protected $telegramService;

    public function __construct()
    {
        $this->telegramService = new TelegramBotService();
    }

    public function setup(Request $request, Integration $integration)
    {
        $this->authorize('update', $integration);

        $validated = $request->validate([
            'bot_token' => 'required|string',
        ]);

        try {
            // Test bot token first
            $botInfo = $this->telegramService->getBotInfo($validated['bot_token']);
            
            if (!$botInfo) {
                return back()->withErrors(['bot_token' => 'Invalid bot token']);
            }

            // Set webhook
            $webhookUrl = route('telegram.webhook', ['token' => $validated['bot_token']]);
            $webhookResult = $this->telegramService->setWebhook($webhookUrl, $validated['bot_token']);

            if (!$webhookResult) {
                return back()->withErrors(['bot_token' => 'Failed to set webhook']);
            }

            // Update integration
            $integration->update([
                'telegram_bot_token' => encrypt($validated['bot_token']),
                'telegram_username' => $botInfo['username'],
                'webhook_url' => $webhookUrl,
                'is_connected' => true,
                'settings' => [
                    'bot_name' => $botInfo['first_name'],
                    'bot_id' => $botInfo['id'],
                ]
            ]);

            return back()->with('success', 'Telegram bot connected successfully!');

        } catch (\Exception $e) {
            Log::error('Telegram setup error: ' . $e->getMessage());
            return back()->withErrors(['bot_token' => 'Failed to setup Telegram bot']);
        }
    }

    public function webhook(Request $request, $token)
    {
        try {
            // Find integration by token
            $integration = Integration::where('type', 'telegram')
                ->whereNotNull('telegram_bot_token')
                ->get()
                ->first(function ($integration) use ($token) {
                    return decrypt($integration->telegram_bot_token) === $token;
                });

            if (!$integration) {
                return response()->json(['error' => 'Integration not found'], 404);
            }

            $telegramService = new TelegramBotService($token);
            $update = $request->all();

            $this->processUpdate($telegramService, $integration, $update);

            return response()->json(['ok' => true]);

        } catch (\Exception $e) {
            Log::error('Telegram webhook error: ' . $e->getMessage());
            return response()->json(['error' => 'Internal error'], 500);
        }
    }

    protected function processUpdate($telegramService, $integration, $update)
    {
        if (isset($update['message'])) {
            $this->processMessage($telegramService, $integration, $update['message']);
        }

        if (isset($update['callback_query'])) {
            $this->processCallbackQuery($telegramService, $integration, $update['callback_query']);
        }
    }

    protected function processMessage($telegramService, $integration, $message)
    {
        $chatId = $message['chat']['id'];
        $messageText = $message['text'] ?? '[Non-text message]';

        // Get bot response using existing message controller logic
        $bot = $integration->bots->first();
        if (!$bot) {
            $telegramService->sendMessage($chatId, 'Bot not configured. Please contact support.');
            return;
        }

        try {
            $messageController = new \App\Http\Controllers\WhatsAppMessageController(
                app(\App\Services\OpenAIService::class)
            );

            // Create request object
            $fakeRequest = new Request([
                'integrationId' => $integration->id,
                'sender' => $chatId,
                'message' => $messageText,
            ]);

            $response = $messageController->handleIncomingMessage($fakeRequest);
            $responseData = $response->getData(true);

            if (isset($responseData['response'])) {
                $telegramService->sendMessage($chatId, $responseData['response']);
            }

        } catch (\Exception $e) {
            Log::error('Error processing Telegram message: ' . $e->getMessage());
            $telegramService->sendMessage($chatId, 'Sorry, I encountered an error. Please try again.');
        }
    }

    protected function processCallbackQuery($telegramService, $integration, $callbackQuery)
    {
        $chatId = $callbackQuery['message']['chat']['id'];
        $data = $callbackQuery['data'];
        $queryId = $callbackQuery['id'];

        // Answer callback query
        $telegramService->answerCallbackQuery($queryId, 'Processing...');

        // Process as regular message
        $this->processMessage($telegramService, $integration, [
            'chat' => ['id' => $chatId],
            'text' => $data
        ]);
    }

    public function disconnect(Integration $integration)
    {
        $this->authorize('update', $integration);

        try {
            if ($integration->telegram_bot_token) {
                $token = decrypt($integration->telegram_bot_token);
                $telegramService = new TelegramBotService($token);
                $telegramService->deleteWebhook();
            }

            $integration->update([
                'telegram_bot_token' => null,
                'telegram_username' => null,
                'webhook_url' => null,
                'is_connected' => false,
                'settings' => null,
            ]);

            return back()->with('success', 'Telegram bot disconnected successfully!');

        } catch (\Exception $e) {
            Log::error('Telegram disconnect error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to disconnect Telegram bot']);
        }
    }
}

// app/Services/TelegramBotService.php (Enhanced version)

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    protected $botToken;
    protected $baseUrl;

    public function __construct($botToken = null)
    {
        $this->botToken = $botToken ?: config('services.telegram.bot_token');
        $this->baseUrl = "https://api.telegram.org/bot{$this->botToken}";
    }

    public function getBotInfo($token = null)
    {
        $url = $token ? "https://api.telegram.org/bot{$token}/getMe" : "{$this->baseUrl}/getMe";
        
        try {
            $response = Http::get($url);
            
            if ($response->successful()) {
                return $response->json()['result'];
            }
            
            return false;
        } catch (\Exception $e) {
            Log::error('Telegram getBotInfo error: ' . $e->getMessage());
            return false;
        }
    }

    public function setWebhook($url, $token = null)
    {
        $apiUrl = $token ? "https://api.telegram.org/bot{$token}/setWebhook" : "{$this->baseUrl}/setWebhook";
        
        try {
            $response = Http::post($apiUrl, [
                'url' => $url,
                'allowed_updates' => ['message', 'callback_query'],
                'drop_pending_updates' => true,
            ]);

            return $response->successful() ? $response->json() : false;
        } catch (\Exception $e) {
            Log::error('Telegram setWebhook error: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteWebhook($token = null)
    {
        $url = $token ? "https://api.telegram.org/bot{$token}/deleteWebhook" : "{$this->baseUrl}/deleteWebhook";
        
        try {
            $response = Http::post($url);
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Telegram deleteWebhook error: ' . $e->getMessage());
            return false;
        }
    }

    public function sendMessage($chatId, $text, $options = [])
    {
        try {
            $params = array_merge([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ], $options);

            $response = Http::post("{$this->baseUrl}/sendMessage", $params);
            
            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Telegram sendMessage error: ' . $response->body());
            return false;
        } catch (\Exception $e) {
            Log::error('Telegram sendMessage exception: ' . $e->getMessage());
            return false;
        }
    }

    public function answerCallbackQuery($callbackQueryId, $text = null)
    {
        try {
            $response = Http::post("{$this->baseUrl}/answerCallbackQuery", [
                'callback_query_id' => $callbackQueryId,
                'text' => $text,
            ]);
            
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Telegram answerCallbackQuery error: ' . $e->getMessage());
            return false;
        }
    }
}
