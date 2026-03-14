<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { MessageSquare, Sparkles, Plus } from 'lucide-vue-next';
import ChatAppLayout from '@/components/ChatAppLayout.vue';

interface Participant { id: number; name: string }
interface PendingInvitation { id: number; display: string }
interface LastMessage { content: string; sender_type: 'user' | 'ai' }
interface Chat {
    id: number;
    context_type: string;
    title: string | null;
    status: 'waiting' | 'active' | 'finalized';
    created_by: Participant;
    participants: Participant[];
    pending_invitations: PendingInvitation[];
    messages_count: number;
    unread_count: number;
    last_message: LastMessage | null;
    updated_at: string;
}
interface ContextType { value: string; label: string }

const props = defineProps<{
    chats: Chat[];
    contextTypes: ContextType[];
    onlineUserIds?: number[];
}>();
</script>

<template>
    <Head title="Messages" />

    <ChatAppLayout :chats="chats" :context-types="contextTypes" :active-chat-id="null">
        <!-- Right panel: empty / select a chat -->
        <div class="flex flex-1 flex-col items-center justify-center bg-[#F8F7FF] text-center p-8">
            <div class="flex h-20 w-20 items-center justify-center rounded-3xl bg-violet-100 shadow-inner">
                <MessageSquare class="h-9 w-9 text-violet-400" />
            </div>
            <h2 class="mt-5 text-lg font-bold text-gray-800">Your conversations</h2>
            <p class="mt-2 max-w-xs text-sm leading-relaxed text-gray-400">
                Select a session from the list to continue, or start a new one and invite someone.
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-2.5">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-violet-100 bg-white px-3.5 py-1.5 text-xs font-medium text-violet-600">
                    <Sparkles class="h-3 w-3" /> AI-guided mediation
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-full border border-gray-100 bg-white px-3.5 py-1.5 text-xs font-medium text-gray-500">
                    Up to 3 participants
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-full border border-gray-100 bg-white px-3.5 py-1.5 text-xs font-medium text-gray-500">
                    Completely private
                </span>
            </div>
        </div>
    </ChatAppLayout>
</template>
