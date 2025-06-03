<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MessengerService
{
    protected $pageAccessToken;
    protected $verifyToken;
    protected $baseUrl = 'https://graph.facebook.com/v18.0';

    public function __construct($pageAccessToken = null)
    {
        $this->pageAccessToken = $pageAccessToken ?: config('services.messenger.page_access_token');
        $this->verifyToken = config('services.messenger.verify_token');
    }

    public function sendMessage($recipientId, $message)
    {
        try {
            $response = Http::post("{$this->baseUrl}/me/messages", [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $message],
                'access_token' => $this->pageAccessToken
            ]);

            return $response->successful() ? $response->json() : false;
        } catch (\Exception $e) {
            Log::error('Messenger send error: ' . $e->getMessage());
            return false;
        }
    }

    public function verifyWebhook($mode, $token, $challenge)
    {
        if ($mode === 'subscribe' && $token === $this->verifyToken) {
            return $challenge;
        }
        return null;
    }

    public function processWebhook($payload)
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['messaging'] ?? [] as $event) {
                if (isset($event['message']['text'])) {
                    $this->handleMessage($event);
                }
            }
        }
        return true;
    }

    protected function handleMessage($event)
    {
        $senderId = $event['sender']['id'];
        $message = $event['message']['text'];
        $pageId = $event['recipient']['id'];

        // Find integration and process message
        $integration = \App\Models\Integration::where('type', 'messenger')
            ->where('messenger_page_id', $pageId)
            ->first();

        if ($integration) {
            $this->processMessage($integration, $senderId, $message);
        }
    }

    protected function processMessage($integration, $senderId, $message)
    {
        $bot = $integration->bots->first();
        if (!$bot) return;

        // Use existing message controller logic
        $controller = new \App\Http\Controllers\WhatsAppMessageController(
            app(\App\Services\OpenAIService::class)
        );

        $request = new \Illuminate\Http\Request([
            'integrationId' => $integration->id,
            'sender' => $senderId,
            'message' => $message,
        ]);

        try {
            $response = $controller->handleIncomingMessage($request);
            $responseData = $response->getData(true);
            
            if (isset($responseData['response'])) {
                $this->sendMessage($senderId, $responseData['response']);
            }
        } catch (\Exception $e) {
            Log::error('Messenger message processing error: ' . $e->getMessage());
        }
    }
}
