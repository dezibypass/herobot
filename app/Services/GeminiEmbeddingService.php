<?php

// app/Services/GeminiEmbeddingService.php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiEmbeddingService
{
    protected $apiKey;
    protected $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
    }

    public function createEmbedding($text)
    {
        if (is_array($text)) {
            return $this->createBatchEmbeddings($text);
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/text-embedding-004:embedContent?key={$this->apiKey}", [
                'model' => 'models/text-embedding-004',
                'content' => [
                    'parts' => [
                        [
                            'text' => $text
                        ]
                    ]
                ]
            ]);

            if ($response->successful()) {
                return $response->json()['embedding']['values'];
            }

            throw new \Exception('Failed to create Gemini embedding: ' . $response->body());
        } catch (\Exception $e) {
            Log::error('Error creating Gemini embedding: ' . $e->getMessage());
            throw $e;
        }
    }

    public function createBatchEmbeddings(array $texts)
    {
        try {
            $embeddings = [];
            
            // Gemini API doesn't support batch embeddings, so we process individually
            foreach ($texts as $text) {
                $embeddings[] = $this->createEmbedding($text);
            }

            return $embeddings;
        } catch (\Exception $e) {
            Log::error('Error creating batch Gemini embeddings: ' . $e->getMessage());
            throw $e;
        }
    }
}

// Update config/services.php
// Add this to the return array:
/*
'gemini' => [
    'api_key' => env('GEMINI_API_KEY'),
],
*/

// Update app/Services/OpenAIService.php to support multiple embedding providers
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    protected $provider;
    protected $openAIService;
    protected $geminiService;

    public function __construct()
    {
        $this->provider = config('services.embedding.provider', 'openai');
        $this->openAIService = new OpenAIService();
        $this->geminiService = new GeminiEmbeddingService();
    }

    public function createEmbedding($text)
    {
        switch ($this->provider) {
            case 'gemini':
                return $this->geminiService->createEmbedding($text);
            case 'openai':
            default:
                return $this->openAIService->createEmbedding($text);
        }
    }

    public function createBatchEmbeddings(array $texts)
    {
        switch ($this->provider) {
            case 'gemini':
                return $this->geminiService->createBatchEmbeddings($texts);
            case 'openai':
            default:
                return $this->openAIService->createBatchEmbeddings($texts);
        }
    }
}

// Update .env.example to include:
/*
# Gemini
GEMINI_API_KEY=

# Embedding Provider (openai, gemini)
EMBEDDING_PROVIDER=openai
*/
