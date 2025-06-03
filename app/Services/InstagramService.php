<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramService
{
    protected $accessToken;
    protected $verifyToken;
    protected $baseUrl = 'https://graph.facebook.com/v18.0';

    public function __construct($accessToken = null)
    {
        $this->accessToken = $accessToken ?: config('services.instagram.access_token');
        $this->verifyToken = config('services.instagram.verify_token');
    }

    public function sendMessage($recipientId, $message)
    {
        try {
            $response = Http::post("{$this->baseUrl}/me/messages", [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $message],
                'access_token' => $this->accessToken
            ]);

            return $response->successful() ? $response->json() : false;
        } catch (\Exception $e) {
            Log::error('Instagram send error: ' . $e->getMessage());
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

        $integration = \App\Models\Integration::where('type', 'instagram')
            ->where('instagram_page_id', $pageId)
            ->first();

        if ($integration) {
            $this->processMessage($integration, $senderId, $message);
        }
    }

    protected function processMessage($integration, $senderId, $message)
    {
        $bot = $integration->bots->first();
        if (!$bot) return;

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
            Log::error('Instagram message processing error: ' . $e->getMessage());
        }
    }
}
