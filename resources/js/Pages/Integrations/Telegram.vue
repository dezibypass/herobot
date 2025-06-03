<template>
    <AppLayout :title="`${integration.name} - Telegram Setup`">
        <div class="space-y-6">
            <div class="sm:flex sm:items-center">
                <div class="sm:flex-auto">
                    <h1 class="text-xl font-semibold leading-6 text-gray-900">{{ integration.name }}</h1>
                    <p class="mt-2 text-sm text-gray-700">Configure your Telegram bot integration</p>
                </div>
                <div class="mt-4 sm:ml-16 sm:mt-0">
                    <SecondaryButton :href="route('integrations.show', integration.id)">
                        Back to Integration
                    </SecondaryButton>
                </div>
            </div>

            <!-- Setup Instructions -->
            <div v-if="!integration.is_connected" class="bg-blue-50 border border-blue-200 rounded-lg p-6">
                <h3 class="text-lg font-medium text-blue-900 mb-4">Setup Your Telegram Bot</h3>
                <ol class="list-decimal list-inside space-y-2 text-blue-800">
                    <li>Open Telegram and search for <strong>@BotFather</strong></li>
                    <li>Send <code>/newbot</code> command</li>
                    <li>Follow the instructions to create your bot</li>
                    <li>Copy the bot token provided by BotFather</li>
                    <li>Paste the token below and click "Connect Bot"</li>
                </ol>
            </div>

            <!-- Bot Configuration Form -->
            <div v-if="!integration.is_connected" class="bg-white shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Bot Configuration</h3>
                    
                    <form @submit.prevent="setupBot" class="space-y-4">
                        <div>
                            <InputLabel for="bot_token" value="Bot Token" />
                            <TextInput
                                id="bot_token"
                                v-model="form.bot_token"
                                type="password"
                                class="mt-1 block w-full"
                                placeholder="Enter your bot token from BotFather"
                                required
                            />
                            <InputError class="mt-2" :message="form.errors.bot_token" />
                            <p class="mt-1 text-sm text-gray-500">
                                Format: 123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ
                            </p>
                        </div>

                        <div class="flex justify-end">
                            <PrimaryButton 
                                :class="{ 'opacity-25': form.processing }" 
                                :disabled="form.processing"
                            >
                                <span v-if="form.processing">Connecting...</span>
                                <span v-else>Connect Bot</span>
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Connected Bot Info -->
            <div v-else class="bg-white shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <div class="sm:flex sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-lg font-medium leading-6 text-gray-900">Telegram Bot Connected</h3>
                            <div class="mt-2 max-w-xl text-sm text-gray-500">
                                <p>Your Telegram bot is successfully connected and ready to receive messages.</p>
                            </div>
                        </div>
                        <div class="mt-5 sm:mt-0 sm:ml-6 sm:flex sm:flex-shrink-0 sm:items-center">
                            <CheckCircleIcon class="h-8 w-8 text-green-400" aria-hidden="true" />
                        </div>
                    </div>

                    <div class="mt-6 border-t border-gray-200 pt-6">
                        <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Bot Username</dt>
                                <dd class="mt-1 text-sm text-gray-900">@{{ integration.telegram_username }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Bot Name</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ integration.settings?.bot_name || 'N/A' }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Webhook Status</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        Active
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Bot Link</dt>
                                <dd class="mt-1 text-sm text-blue-600">
                                    <a :href="`https://t.me/${integration.telegram_username}`" target="_blank" class="hover:underline">
                                        https://t.me/{{ integration.telegram_username }}
                                    </a>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div class="mt-6 flex space-x-3">
                        <SecondaryButton @click="testBot" :disabled="testingBot">
                            <span v-if="testingBot">Testing...</span>
                            <span v-else>Test Bot</span>
                        </SecondaryButton>
                        
                        <DangerButton @click="showDisconnectModal = true">
                            Disconnect Bot
                        </DangerButton>
                    </div>
                </div>
            </div>

            <!-- Bot Commands Configuration -->
            <div v-if="integration.is_connected" class="bg-white shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Bot Commands</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        You can set up these commands in BotFather to make your bot more user-friendly:
                    </p>
                    
                    <div class="bg-gray-50 rounded-md p-4">
                        <code class="text-sm">
                            start - Start conversation with the bot<br>
                            help - Get help information<br>
                            menu - Show main menu<br>
                            support - Contact human support
                        </code>
                    </div>
                </div>
            </div>
        </div>

        <!-- Disconnect Confirmation Modal -->
        <ConfirmationModal :show="showDisconnectModal" @close="showDisconnectModal = false">
            <template #title>
                Disconnect Telegram Bot
            </template>

            <template #content>
                Are you sure you want to disconnect this Telegram bot? This will:
                <ul class="mt-2 list-disc list-inside text-sm text-gray-600">
                    <li>Remove the webhook from Telegram</li>
                    <li>Stop receiving messages</li>
                    <li>Require reconfiguration to reconnect</li>
                </ul>
            </template>

            <template #footer>
                <SecondaryButton @click="showDisconnectModal = false">
                    Cancel
                </SecondaryButton>

                <DangerButton
                    class="ml-3"
                    :class="{ 'opacity-25': disconnectForm.processing }"
                    :disabled="disconnectForm.processing"
                    @click="disconnectBot"
                >
                    Disconnect Bot
                </DangerButton>
            </template>
        </ConfirmationModal>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import { CheckCircleIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    integration: Object,
});

const form = useForm({
    bot_token: '',
});

const disconnectForm = useForm({});

const showDisconnectModal = ref(false);
const testingBot = ref(false);

const setupBot = () => {
    form.post(route('telegram.setup', props.integration.id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
    });
};

const disconnectBot = () => {
    disconnectForm.post(route('telegram.disconnect', props.integration.id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            showDisconnectModal.value = false;
        },
    });
};

const testBot = async () => {
    testingBot.value = true;
    
    try {
        // You can implement a test message endpoint
        await axios.post(route('telegram.test', props.integration.id));
        alert('Test message sent! Check your Telegram bot.');
    } catch (error) {
        alert('Failed to send test message.');
    } finally {
        testingBot.value = false;
    }
};
</script>

<!-- resources/js/Pages/Integrations/Components/TelegramCard.vue -->
<template>
    <div class="rounded-xl border border-gray-200 bg-white p-6">
        <div class="flex items-center">
            <div class="flex-shrink-0">
                <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-blue-500">
                    <svg class="h-6 w-6 text-white" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.568 8.16l-1.61 7.593c-.12.513-.439.642-.89.4l-2.456-1.811-1.184 1.139c-.131.131-.242.242-.497.242l.177-2.506 4.589-4.147c.199-.177-.043-.275-.309-.098L9.28 13.925l-2.414-.754c-.525-.164-.535-.525.109-.777l9.422-3.634c.438-.164.822.097.68.598z"/>
                    </svg>
                </div>
            </div>
            <div class="ml-4 flex-1">
                <h3 class="text-lg font-medium text-gray-900">Telegram Bot</h3>
                <p class="text-sm text-gray-500">
                    Connect your Telegram bot to receive and respond to messages automatically.
                </p>
            </div>
        </div>

        <div class="mt-6">
            <div v-if="integration.type === 'telegram' && integration.is_connected" class="flex items-center">
                <CheckCircleIcon class="h-5 w-5 text-green-400" />
                <span class="ml-2 text-sm text-green-700">Connected as @{{ integration.telegram_username }}</span>
            </div>
            <div v-else class="text-sm text-gray-500">
                Not configured
            </div>
        </div>

        <div class="mt-6">
            <Link 
                v-if="integration.type === 'telegram'"
                :href="route('telegram.setup', integration.id)"
                class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700"
            >
                {{ integration.is_connected ? 'Manage' : 'Setup' }} Telegram Bot
            </Link>
            <SecondaryButton 
                v-else
                @click="$emit('create-integration', 'telegram')"
            >
                Create Telegram Integration
            </SecondaryButton>
        </div>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { CheckCircleIcon } from '@heroicons/vue/24/outline';

defineProps({
    integration: Object,
});

defineEmits(['create-integration']);
</script>
