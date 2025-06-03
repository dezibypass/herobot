<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppBusinessService
{
    protected $accessToken;
    protected $phoneNumberId;
    protected $webhookVerifyToken;
    protected $baseUrl = 'https://graph.facebook.com/v18.0';

    public function __construct($accessToken = null, $phoneNumberId = null)
    {
        $this->accessToken = $accessToken ?: config('services.whatsapp_business.access_token');
        $this->phoneNumberId = $phoneNumberId ?: config('services.whatsapp_business.phone_number_id');
        $this->webhookVerifyToken = config('services.whatsapp_business.webhook_verify_token');
    }

    public function sendMessage($to, $message, $type = 'text')
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
        ];

        switch ($type) {
            case 'text':
                $payload['type'] = 'text';
                $payload['text'] = ['body' => $message];
                break;
            case 'template':
                $payload['type'] = 'template';
                $payload['template'] = $message;
                break;
            case 'interactive':
                $payload['type'] = 'interactive';
                $payload['interactive'] = $message;
                break;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/{$this->phoneNumberId}/messages", $payload);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('WhatsApp Business API error: ' . $response->body());
            return ['error' => $response->json()];
        } catch (\Exception $e) {
            Log::error('WhatsApp Business API exception: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    public function markAsRead($messageId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/{$this->phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $messageId,
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('WhatsApp Business mark as read error: ' . $e->getMessage());
            return false;
        }
    }

    public function getMedia($mediaId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
            ])->get("{$this->baseUrl}/{$mediaId}");

            if ($response->successful()) {
                $mediaData = $response->json();
                
                // Download the actual media file
                $mediaResponse = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ])->get($mediaData['url']);

                return [
                    'success' => true,
                    'data' => $mediaResponse->body(),
                    'mime_type' => $mediaData['mime_type'],
                    'file_size' => $mediaData['file_size'],
                ];
            }

            return ['success' => false, 'error' => $response->body()];
        } catch (\Exception $e) {
            Log::error('WhatsApp Business get media error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function verifyWebhook($mode, $token, $challenge)
    {
        if ($mode === 'subscribe' && $token === $this->webhookVerifyToken) {
            return $challenge;
        }
        return null;
    }

    public function processWebhook($payload)
    {
        Log::info('WhatsApp Business webhook received:', $payload);

        if (!isset($payload['entry'])) {
            return false;
        }

        foreach ($payload['entry'] as $entry) {
            if (!isset($entry['changes'])) {
                continue;
            }

            foreach ($entry['changes'] as $change) {
                if ($change['field'] !== 'messages') {
                    continue;
                }

                $value = $change['value'];
                
                // Process status updates
                if (isset($value['statuses'])) {
                    $this->processStatusUpdates($value['statuses']);
                }

                // Process incoming messages
                if (isset($value['messages'])) {
                    $this->processIncomingMessages($value['messages'], $value['metadata'] ?? []);
                }
            }
        }

        return true;
    }

    protected function processIncomingMessages($messages, $metadata)
    {
        foreach ($messages as $message) {
            $from = $message['from'];
            $messageId = $message['id'];
            $timestamp = $message['timestamp'];

            // Mark as read
            $this->markAsRead($messageId);

            // Extract message content based on type
            $content = $this->extractMessageContent($message);
            
            if ($content) {
                // Find the integration for this phone number
                $phoneNumberId = $metadata['phone_number_id'] ?? $this->phoneNumberId;
                $integration = $this->findIntegrationByPhoneNumberId($phoneNumberId);

                if ($integration) {
                    // Process the message
                    $this->handleIncomingMessage($integration, $from, $content, $messageId);
                }
            }
        }
    }

    protected function extractMessageContent($message)
    {
        $type = $message['type'];

        switch ($type) {
            case 'text':
                return $message['text']['body'];
            
            case 'image':
                return "[Image received]";
            
            case 'document':
                return "[Document received: " . ($message['document']['filename'] ?? 'Unknown') . "]";
            
            case 'audio':
                return "[Audio message received]";
            
            case 'video':
                return "[Video received]";
            
            case 'location':
                $location = $message['location'];
                return "[Location: {$location['latitude']}, {$location['longitude']}]";
            
            case 'interactive':
                if (isset($message['interactive']['button_reply'])) {
                    return $message['interactive']['button_reply']['title'];
                } elseif (isset($message['interactive']['list_reply'])) {
                    return $message['interactive']['list_reply']['title'];
                }
                return "[Interactive message]";
            
            default:
                return "[Unsupported message type: {$type}]";
        }
    }

    protected function processStatusUpdates($statuses)
    {
        foreach ($statuses as $status) {
            Log::info('WhatsApp message status update:', $status);
            // You can update message delivery status in your database here
        }
    }

    protected function findIntegrationByPhoneNumberId($phoneNumberId)
    {
        return \App\Models\Integration::where('type', 'whatsapp_business')
            ->where('whatsapp_phone_number_id', $phoneNumberId)
            ->first();
    }

    protected function handleIncomingMessage($integration, $from, $content, $messageId)
    {
        // This should integrate with your existing message handling logic
        // Similar to your current WhatsAppMessageController
        
        $bot = $integration->bots->first();
        if (!$bot) {
            return;
        }

        // Generate response using your existing logic
        $controller = new \App\Http\Controllers\WhatsAppMessageController(
            app(\App\Services\OpenAIService::class)
        );
        
        // Create a mock request object
        $request = new \Illuminate\Http\Request([
            'integrationId' => $integration->id,
            'sender' => $from,
            'message' => $content,
            'messageId' => $messageId,
        ]);

        try {
            $response = $controller->handleIncomingMessage($request);
            $responseData = $response->getData(true);
            
            if (isset($responseData['response'])) {
                $this->sendMessage($from, $responseData['response']);
            }
        } catch (\Exception $e) {
            Log::error('Error handling WhatsApp Business message: ' . $e->getMessage());
            $this->sendMessage($from, 'Sorry, I encountered an error. Please try again later.');
        }
    }
}

// app/Http/Controllers/WhatsAppBusinessWebhookController.php
namespace App\Http\Controllers;

use App\Services\WhatsAppBusinessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppBusinessWebhookController extends Controller
{
    protected $whatsappService;

    public function __construct(WhatsAppBusinessService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $result = $this->whatsappService->verifyWebhook($mode, $token, $challenge);
        
        if ($result) {
            return response($result, 200);
        }
        
        return response('Forbidden', 403);
    }

    public function webhook(Request $request)
    {
        try {
            $payload = $request->all();
            $this->whatsappService->processWebhook($payload);
            
            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('WhatsApp Business webhook error: ' . $e->getMessage());
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }
}

// Update config/services.php
/*
'whatsapp_business' => [
    'access_token' => env('WHATSAPP_BUSINESS_ACCESS_TOKEN'),
    'phone_number_id' => env('WHATSAPP_BUSINESS_PHONE_NUMBER_ID'),
    'webhook_verify_token' => env('WHATSAPP_BUSINESS_WEBHOOK_VERIFY_TOKEN'),
    'app_id' => env('WHATSAPP_BUSINESS_APP_ID'),
    'app_secret' => env('WHATSAPP_BUSINESS_APP_SECRET'),
],
*/

// Add to database/migrations/create_integrations_table.php
/*
$table->string('whatsapp_phone_number_id')->nullable();
$table->string('whatsapp_business_account_id')->nullable();
$table->text('whatsapp_access_token')->nullable();
*/

// Add routes to routes/api.php
/*
Route::get('/whatsapp-business/webhook', [WhatsAppBusinessWebhookController::class, 'verify']);
Route::post('/whatsapp-business/webhook', [WhatsAppBusinessWebhookController::class, 'webhook']);
*/

// Update .env.example
/*
# WhatsApp Business API
WHATSAPP_BUSINESS_ACCESS_TOKEN=
WHATSAPP_BUSINESS_PHONE_NUMBER_ID=
WHATSAPP_BUSINESS_WEBHOOK_VERIFY_TOKEN=
WHATSAPP_BUSINESS_APP_ID=
WHATSAPP_BUSINESS_APP_SECRET=
*/
