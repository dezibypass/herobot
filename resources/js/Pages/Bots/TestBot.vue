<template>
    <AppLayout :title="`Test ${bot.name}`">
        <div class="space-y-6">
            <div class="sm:flex sm:items-center">
                <div class="sm:flex-auto">
                    <h1 class="text-xl font-semibold leading-6 text-gray-900">Test {{ bot.name }}</h1>
                    <p class="mt-2 text-sm text-gray-700">Test your bot's responses in real-time</p>
                </div>
                <div class="mt-4 sm:ml-16 sm:mt-0">
                    <SecondaryButton :href="route('bots.show', bot.id)">
                        Back to Bot
                    </SecondaryButton>
                </div>
            </div>

            <!-- Chat Interface -->
            <div class="bg-white shadow rounded-lg h-96 flex flex-col">
                <!-- Chat Header -->
                <div class="bg-gray-50 px-4 py-3 border-b">
                    <div class="flex items-center">
                        <div class="w-8 h-8 bg-indigo-600 rounded-full flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z"/>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">{{ bot.name }}</p>
                            <p class="text-xs text-gray-500">Test Environment</p>
                        </div>
                    </div>
                </div>

                <!-- Messages Container -->
                <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-4">
                    <div v-for="message in messages" :key="message.id" 
                         :class="['flex', message.role === 'user' ? 'justify-end' : 'justify-start']">
                        <div :class="[
                            'max-w-xs lg:max-w-md px-4 py-2 rounded-lg',
                            message.role === 'user' 
                                ? 'bg-indigo-600 text-white' 
                                : 'bg-gray-200 text-gray-900'
                        ]">
                            <p class="text-sm whitespace-pre-wrap">{{ message.content }}</p>
                            <p class="text-xs mt-1 opacity-75">
                                {{ formatTime(message.timestamp) }}
                            </p>
                        </div>
                    </div>

                    <!-- Loading indicator -->
                    <div v-if="isLoading" class="flex justify-start">
                        <div class="bg-gray-200 px-4 py-2 rounded-lg">
                            <div class="flex space-x-1">
                                <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce"></div>
                                <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.1s"></div>
                                <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Input Area -->
                <div class="border-t p-4">
                    <form @submit.prevent="sendMessage" class="flex space-x-3">
                        <input
                            v-model="messageInput"
                            type="text"
                            placeholder="Type your message..."
                            class="flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            :disabled="isLoading"
                        />
                        <PrimaryButton type="submit" :disabled="isLoading || !messageInput.trim()">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/>
                            </svg>
                        </PrimaryButton>
                    </form>
                </div>
            </div>

            <!-- Test Controls -->
            <div class="bg-white shadow rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Test Controls</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <button
                        @click="clearChat"
                        class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Clear Chat
                    </button>
                    <button
                        @click="resetToDefault"
                        class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Reset Context
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, nextTick, onMounted } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    bot: Object,
});

const messages = ref([]);
const messageInput = ref('');
const isLoading = ref(false);
const messagesContainer = ref(null);

const sendMessage = async () => {
    if (!messageInput.value.trim() || isLoading.value) return;

    const userMessage = {
        id: Date.now(),
        role: 'user',
        content: messageInput.value,
        timestamp: new Date(),
    };

    messages.value.push(userMessage);
    const userInput = messageInput.value;
    messageInput.value = '';
    isLoading.value = true;

    await scrollToBottom();

    try {
        const response = await axios.post(route('bots.test', props.bot.id), {
            message: userInput,
            context: messages.value.slice(-10), // Send last 10 messages for context
        });

        const botMessage = {
            id: Date.now() + 1,
            role: 'assistant',
            content: response.data.response,
            timestamp: new Date(),
        };

        messages.value.push(botMessage);
    } catch (error) {
        const errorMessage = {
            id: Date.now() + 1,
            role: 'assistant',
            content: 'Sorry, I encountered an error. Please try again.',
            timestamp: new Date(),
        };
        messages.value.push(errorMessage);
    } finally {
        isLoading.value = false;
        await scrollToBottom();
    }
};

const scrollToBottom = async () => {
    await nextTick();
    if (messagesContainer.value) {
        messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
};

const clearChat = () => {
    messages.value = [];
};

const resetToDefault = () => {
    clearChat();
    // Add welcome message
    messages.value.push({
        id: Date.now(),
        role: 'assistant',
        content: 'Hello! I\'m your bot assistant. How can I help you today?',
        timestamp: new Date(),
    });
};

const formatTime = (timestamp) => {
    return new Date(timestamp).toLocaleTimeString([], { 
        hour: '2-digit', 
        minute: '2-digit' 
    });
};

onMounted(() => {
    resetToDefault();
});
</script>

// Add route to routes/web.php
/*
Route::get('/bots/{bot}/test', [BotController::class, 'test'])->name('bots.test');
Route::post('/bots/{bot}/test', [BotController::class, 'testMessage'])->name('bots.test.message');
*/

// Add methods to app/Http/Controllers/BotController.php
/*
public function test(Bot $bot)
{
    return inertia('Bots/TestBot', [
        'bot' => $bot,
    ]);
}

public function testMessage(Request $request, Bot $bot)
{
    $validatedData = $request->validate([
        'message' => 'required|string',
        'context' => 'array',
    ]);

    // Use your existing message generation logic
    $response = $this->generateTestResponse($bot, $validatedData['message'], $validatedData['context'] ?? []);

    return response()->json([
        'response' => $response,
    ]);
}

private function generateTestResponse(Bot $bot, string $message, array $context = [])
{
    // Implement your bot response logic here
    // This should be similar to your WhatsApp message handling
    // but without saving to database
    
    $openAIService = app(OpenAIService::class);
    
    // Build context from previous messages
    $messages = [
        ['role' => 'system', 'content' => $bot->prompt],
    ];
    
    // Add context messages
    foreach ($context as $msg) {
        if (isset($msg['role']) && isset($msg['content'])) {
            $messages[] = [
                'role' => $msg['role'] === 'user' ? 'user' : 'assistant',
                'content' => $msg['content']
            ];
        }
    }
    
    // Add current message
    $messages[] = ['role' => 'user', 'content' => $message];
    
    // Get knowledge context
    $relevantKnowledge = $openAIService->searchSimilarKnowledge($message, $bot, 3);
    
    if ($relevantKnowledge->isNotEmpty()) {
        $knowledgeContext = $relevantKnowledge->pluck('text')->implode("\n\n");
        $messages[0]['content'] .= "\n\nRelevant knowledge:\n" . $knowledgeContext;
    }
    
    // Call your AI service (OpenAI, OpenRouter, etc.)
    // Return the generated response
    
    return "This is a test response from {$bot->name}";
}
*/
