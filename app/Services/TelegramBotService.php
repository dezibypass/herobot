<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    protected $botToken;
    protected $baseUrl;

    public function __construct($botToken = null)
    {
        $this->botToken = $botToken ?: config('services.telegram_bot.token');
        $this->baseUrl = "https://api.telegram.org/bot{$this->botToken}";
    }

    public function setWebhook($url, $secretToken = null)
    {
        try {
            $params = [
                'url' => $url,
                'allowed_updates' => ['message', 'callback_query', 'inline_query'],
                'drop_pending_updates' => true,
            ];

            if ($secretToken) {
                $params['secret_token'] = $secretToken;
            }

            $response = Http::post("{$this->baseUrl}/setWebhook", $params);

            return $response->successful() ? $response->json() : false;
        } catch (\Exception $e) {
            Log::error('Telegram setWebhook error: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteWebhook()
    {
        try {
            $response = Http::post("{$this->baseUrl}/deleteWebhook");
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Telegram deleteWebhook error: ' . $e->getMessage());
            return false;
        }
    }

    public function getWebhookInfo()
    {
        try {
            $response = Http::get("{$this->baseUrl}/getWebhookInfo");
            return $response->successful() ? $response->json() : false;
        } catch (\Exception $e) {
            Log::error('Telegram getWebhookInfo error: ' . $e->getMessage());
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

    public function sendPhoto($chatId, $photo, $caption = null, $options = [])
    {
        try {
            $params = array_merge([
                'chat_id' => $chatId,
                'photo' => $photo,
                'caption' => $caption,
            ], $options);

            $response = Http::post("{$this->baseUrl}/sendPhoto", $params);
            return $response->successful() ? $response->json() : false;
        } catch (\Exception $e) {
            Log::error('Telegram sendPhoto exception: ' . $e->getMessage());
            return false;
        }
    }

    public function sendDocument($chatId, $document, $caption = null, $options = [])
    {
        try {
            $params = array_merge([
                'chat_id' => $chatId,
                'document' => $document,
                'caption' => $caption,
            ], $options);

            $response = Http::post("{$this->baseUrl}/sendDocument", $params);
            return $response->successful() ? $response->json() : false;
        } catch (\Exception $e) {
            Log::error('Telegram sendDocument exception: ' . $e->getMessage());
            return false;
        }
    }

    public function sendInlineKeyboard($chatId, $text, $keyboard, $options = [])
    {
        $replyMarkup = [
            'inline_keyboard' => $keyboard
        ];

        return $this->sendMessage($chatId, $text, array_merge($options, [
            'reply_markup' => json_encode($replyMarkup)
        ]));
    }

    public function editMessageText($chatId, $messageId, $text, $options = [])
    {
        try {
            $params = array_merge([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ], $options);

            $response = Http::post("{$this->baseUrl}/editMessageText", $params);
            return $response->successful() ? $response->json() : false;
        } catch (\Exception $e) {
            Log::error('Telegram editMessageText exception: ' . $e->getMessage());
            return false;
        }
    }

    public function answerCallbackQuery($callbackQueryId, $text = null, $showAlert = false)
    {
        try {
            $params = [
                'callback_query_id' => $callbackQueryId,
                'text' => $text,
                'show_alert' => $showAlert,
            ];

            $response = Http::post("{$this->baseUrl}/answerCallbackQuery", $params);
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Telegram answerCallbackQuery exception: ' . $e->getMessage());
            return false;
        }
    }

    public function getFile($fileId)
    {
        try {
            $response = Http::get("{$this->baseUrl}/getFile", ['file_id' => $fileId]);
            
            if ($response->successful()) {
                $fileData = $response->json()['result'];
                
                // Download the file
                $fileUrl = "https://api.telegram.org/file/bot{$this->botToken}/{$fileData['file_path']}";
                $fileContent = Http::get($fileUrl);
                
                return [
                    'success' => true,
                    'file_path' => $fileData['file_path'],
                    'file_size' => $fileData['file_size'],
                    'content' => $fileContent->body(),
                ];
            }

            return ['success' => false];
        } catch (\Exception $e) {
            Log::error('Telegram getFile exception: ' . $e->getMessage());
            return ['success' => false];
        }
    }

    public function processUpdate($update)
    {
        Log::info('Telegram update received:', $update);

        if (isset($update['message'])) {
            return $this->processMessage($update['message']);
        }

        if (isset($update['callback_query'])) {
            return $this->processCallbackQuery($update['callback_query']);
        }

        if (isset($update['inline_query'])) {
            return $this->processInlineQuery($update['inline_query']);
        }

        return false;
    }

    protected function processMessage($message)
    {
        $chatId = $message['chat']['id'];
        $from = $message['from'];
        $messageId = $message['message_id'];

        // Find integration by bot token
        $integration = $this->findIntegrationByBotToken($this->botToken);
        
        if (!$integration) {
            Log::warning('No integration found for Telegram bot token');
            return false;
        }

        $content = $this->extractMessageContent($message);
        
        if ($content) {
            $this->handleIncomingMessage($integration, $chatId, $content, $messageId);
        }

        return true;
    }

    protected function processCallbackQuery($callbackQuery)
    {
        $chatId = $callbackQuery['message']['chat']['id'];
        $from = $callbackQuery['from'];
        $data = $callbackQuery['data'];
        $queryId = $callbackQuery['id'];

        // Answer the callback query to remove loading state
        $this->answerCallbackQuery($queryId);

        // Find integration
        $integration = $this->findIntegrationByBotToken($this->botToken);
        
        if ($integration) {
            $this->handleIncomingMessage($integration, $chatId, $data, null, 'callback');
        }

        return true;
    }

    protected function processInlineQuery($inlineQuery)
    {
        // Handle inline queries if needed
        return true;
    }

    protected function extractMessageContent($message)
    {
        if (isset($message['text'])) {
            return $message['text'];
        }

        if (isset($message['photo'])) {
            $caption = $message['caption'] ?? '';
            return "[Photo received]" . ($caption ? " Caption: {$caption}" : "");
        }

        if (isset($message['document'])) {
            $filename = $message['document']['file_name'] ?? 'Unknown';
            $caption = $message['caption'] ?? '';
            return "[Document received: {$filename}]" . ($caption ? " Caption: {$caption}" : "");
        }

        if (isset($message['audio'])) {
            return "[Audio message received]";
        }

        if (isset($message['video'])) {
            $caption = $message['caption'] ?? '';
            return "[Video received]" . ($caption ? " Caption: {$caption}" : "");
        }

        if (isset($message['voice'])) {
            return "[Voice message received]";
        }

        if (isset($message['location'])) {
            $lat = $message['location']['latitude'];
            $lng = $message['location']['longitude'];
            return "[Location: {$lat}, {$lng}]";
        }

        if (isset($message['contact'])) {
            $name = $message['contact']['first_name'] . ' ' . ($message['contact']['last_name'] ?? '');
            $phone = $message['contact']['phone_number'] ?? '';
            return "[Contact shared: {$name} {$phone}]";
        }

        return "[Unsupported message type]";
    }

    protected function findIntegrationByBotToken($botToken)
    {
        return \App\Models\Integration::where('type', 'telegram')
            ->where('telegram_bot_token', $botToken)
            ->first();
    }

    protected function handleIncomingMessage($integration, $chatId, $content, $messageId, $type = 'message')
    {
        $bot = $integration->bots->first();
        if (!$bot) {
            $this->sendMessage($chatId, 'Bot not configured. Please contact support.');
            return;
        }

        try {
            // Use your existing message handling logic
            $controller = new \App\Http\Controllers\TelegramMessageController(
                app(\App\Services\OpenAIService::class)
            );
            
            $request = new \Illuminate\Http\Request([
                'integrationId' => $integration->id,
                'chatId' => $chatId,
                'message' => $content,
                'messageId' => $messageId,
                'type' => $type,
            ]);

            $response = $controller->handleIncomingMessage($request);
            $responseData = $response->getData(true);
            
            if (isset($responseData['response'])) {
                $this->sendMessage($chatId, $responseData['response']);
            }
        } catch (\Exception $e) {
            Log::error('Error handling Telegram message: ' . $e->getMessage());
            $this->sendMessage($chatId, 'Sorry, I encountered an error. Please try again later.');
        }
    }

    public function convertMarkdownToTelegram($text)
    {
        // Convert WhatsApp-style formatting to Telegram markdown
        
        // Bold: *text* to *text*
        $text = preg_replace('/(?<!\*)\*(?!\*)([^*]+?)(?<!\*)\*(?!\*)/', '*$1*', $text);
        
        // Italic: _text_ to _text_
        $text = preg_replace('/(?<!_)_(?!_)([^_]+?)(?<!_)_(?!_)/', '_$1_', $text);
        
        // Code: ```text``` to `text`
        $text = preg_replace('/```([^`]+?)```/', '`$1`', $text);
        
        // Strike-through: ~text~ to ~text~
        $text = preg_replace('/~([^~]+?)~/', '~$1~', $text);
        
        return $text;
    }
}

// app/Http/Controllers/TelegramWebhookController.php
namespace App\Http\Controllers;

use App\Services\TelegramBotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function webhook(Request $request)
    {
        try {
            $update = $request->all();
            
            // Get bot token from the request path or header
            $botToken = $this->extractBotToken($request);
            
            if (!$botToken) {
                return response()->json(['error' => 'Bot token not found'], 400);
            }

            $telegramService = new TelegramBotService($botToken);
            $telegramService->processUpdate($update);
            
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            Log::error('Telegram webhook error: ' . $e->getMessage());
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    protected function extractBotToken($request)
    {
        // You can extract bot token from URL path or header
        // Example: /api/telegram/webhook/{botToken}
        return $request->route('botToken') ?: $request->header('X-Telegram-Bot-Token');
    }
}

// app/Http/Controllers/TelegramMessageController.php
namespace App\Http\Controllers;

use App\Models\Integration;
use App\Models\ChatHistory;
use App\Services\OpenAIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramMessageController extends Controller
{
    protected $openAIService;

    public function __construct(OpenAIService $openAIService)
    {
        $this->openAIService = $openAIService;
    }

    public function handleIncomingMessage(Request $request)
    {
        $integrationId = $request->input('integrationId');
        $chatId = $request->input('chatId');
        $messageContent = $request->input('message');

        $integration = Integration::with(['bots', 'team.balance'])->findOrFail($integrationId);
        $bot = $integration->bots->first();
        $team = $integration->team;

        if (!$bot) {
            return response()->json(['error' => 'No bot found for this integration'], 404);
        }

        // Get chat history for this Telegram chat
        $chatHistory = ChatHistory::where('integration_id', $integrationId)
            ->where('sender', $chatId)
            ->latest()
            ->take(5)
            ->get()
            ->reverse()
            ->values();

        $response = $this->generateResponse($bot, $messageContent, $chatHistory);

        // Save chat history
        $this->saveChatHistory($integrationId, $chatId, $messageContent, $response);

        return response()->json(['response' => $response]);
    }

    private function generateResponse($bot, $message, $chatHistory)
    {
        // Use your existing response generation logic
        // This should be similar to your WhatsApp message handling
        
        $relevantKnowledge = $this->openAIService->searchSimilarKnowledge($message, $bot, 3);
        
        $systemPrompt = $bot->prompt;
        if ($relevantKnowledge->isNotEmpty()) {
            $systemPrompt .= "\n\nRelevant knowledge:\n";
            foreach ($relevantKnowledge as $knowledge) {
                $systemPrompt .= "{$knowledge['text']}\n\n";
            }
        }

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
            // Use your existing AI service configuration
            $response = $this->callAIService($messages);
            return $this->convertMarkdownToTelegram($response);
        } catch (\Exception $e) {
            Log::error('Failed to generate Telegram response: ' . $e->getMessage());
            return 'Sorry, I encountered an error. Please try again.';
        }
    }

    private function convertMarkdownToTelegram($text)
    {
        // Convert to Telegram markdown format
        $telegramService = new \App\Services\TelegramBotService();
        return $telegramService->convertMarkdownToTelegram($text);
    }

    private function saveChatHistory($integrationId, $chatId, $message, $response)
    {
        ChatHistory::create([
            'integration_id' => $integrationId,
            'sender' => $chatId,
            'message' => $message,
            'response' => $response
        ]);
    }

    private function callAIService($messages)
    {
        // Use your existing AI service logic here
        // This is a placeholder - implement according to your current setup
        return "This is a test response for Telegram";
    }
}

// Update config/services.php
/*
'telegram_bot' => [
    'token' => env('TELEGRAM_BOT_TOKEN'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
],
*/

// Add to database migrations for integrations table
/*
$table->string('telegram_bot_token')->nullable();
$table->string('telegram_webhook_url')->nullable();
*/

// Add routes to routes/api.php
/*
Route::post('/telegram/webhook/{botToken}', [TelegramWebhookController::class, 'webhook']);
*/

// Update .env.example
/*
# Telegram Bot
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_SECRET=
*/
