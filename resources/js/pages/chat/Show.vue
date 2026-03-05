<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Send, Bot, AlertCircle, CheckCircle2, Users, Hourglass, X, Sparkles, Link2, Copy, Check } from 'lucide-vue-next';

// ── Types ──────────────────────────────────────────────────────────────────
interface Sender { id: number; name: string }
interface Message {
    id: number;
    sender_type: 'user' | 'ai';
    sender: Sender | null;
    content: string;
    created_at: string;
}
interface Participant { id: number; name: string }
interface PendingInvitation { id: number; display: string }
interface Chat {
    id: number;
    context_type: string;
    title: string | null;
    status: 'waiting' | 'active' | 'finalized';
    created_by: Participant;
    participants: Participant[];
    pending_invitations: PendingInvitation[];
}
interface CurrentUser { id: number; name: string }

// ── Props ──────────────────────────────────────────────────────────────────
const props = defineProps<{
    chat: Chat;
    messages: Message[];
    currentUser: CurrentUser;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Chats', href: '/chats' },
    { title: props.chat.title || `${props.chat.context_type} Discussion`, href: `/chats/${props.chat.id}` },
];

// ── Local message state ─────────────────────────────────────────────────────
const localMessages = ref<Message[]>([...props.messages]);
const messagesEndRef = ref<HTMLDivElement | null>(null);
const textareaRef = ref<HTMLTextAreaElement | null>(null);
const isAiThinking = ref(false);

function scrollToBottom() {
    nextTick(() => messagesEndRef.value?.scrollIntoView({ behavior: 'smooth' }));
}

function mergeMessages(incoming: Message[]) {
    const existingIds = new Set(localMessages.value.map(m => m.id));
    const fresh = incoming.filter(m => !existingIds.has(m.id));
    if (!fresh.length) return;
    if (fresh.some(m => m.sender_type === 'ai')) isAiThinking.value = false;
    localMessages.value.push(...fresh);
    scrollToBottom();
}

// When Inertia updates props after a router.post redirect, merge new messages
watch(() => props.messages, mergeMessages, { deep: true });

onMounted(scrollToBottom);

// ── Sending ────────────────────────────────────────────────────────────────
const messageContent = ref('');
const isSending = ref(false);

function sendMessage() {
    const content = messageContent.value.trim();
    if (!content || isSending.value || props.chat.status !== 'active') return;

    messageContent.value = ''; // Clear immediately — don't wait for server
    isSending.value = true;
    isAiThinking.value = true;

    router.post(`/chats/${props.chat.id}/messages`, { content }, {
        preserveScroll: true,
        onError: () => {
            messageContent.value = content; // Restore on validation error
            isAiThinking.value = false;
        },
        onFinish: () => {
            isSending.value = false;
            isAiThinking.value = false; // Safety fallback
            nextTick(() => textareaRef.value?.focus());
        },
    });
}

function handleKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
}

// ── Live polling (new messages from other participants) ────────────────────
const lastMessageId = computed(() => localMessages.value.at(-1)?.id ?? 0);
let pollTimer: ReturnType<typeof setInterval> | null = null;

async function pollMessages() {
    try {
        const res = await fetch(`/chats/${props.chat.id}/messages?after=${lastMessageId.value}`, {
            headers: { Accept: 'application/json' },
        });
        if (res.ok) mergeMessages(await res.json());
    } catch { /* silent */ }
}

// ── Typing indicators ──────────────────────────────────────────────────────
const typingUsers = ref<{ name: string }[]>([]);
let typingTimer: ReturnType<typeof setInterval> | null = null;
let lastTypedAt = 0;

function getCsrf(): string {
    return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
}

function onInput() {
    if (Date.now() - lastTypedAt < 2000) return;
    lastTypedAt = Date.now();
    fetch(`/chats/${props.chat.id}/typing`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': getCsrf(), Accept: 'application/json', 'Content-Type': 'application/json' },
    }).catch(() => {});
}

async function pollTyping() {
    try {
        const res = await fetch(`/chats/${props.chat.id}/typing`, { headers: { Accept: 'application/json' } });
        if (res.ok) typingUsers.value = await res.json();
    } catch { /* silent */ }
}

onMounted(() => {
    if (props.chat.status !== 'finalized') pollTimer = setInterval(pollMessages, 3000);
    if (props.chat.status === 'active') typingTimer = setInterval(pollTyping, 1500);
});

onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer);
    if (typingTimer) clearInterval(typingTimer);
});

// ── Finalize ───────────────────────────────────────────────────────────────
const confirmingFinalize = ref(false);
const isCreator = props.currentUser.id === props.chat.created_by.id;

function finalizeChat() {
    router.post(`/chats/${props.chat.id}/finalize`, {}, {
        onSuccess: () => { confirmingFinalize.value = false; },
    });
}

// ── Invite link ────────────────────────────────────────────────────────────
const inviteLink = ref('');
const linkCopied = ref(false);
const generatingLink = ref(false);

async function generateInviteLink() {
    generatingLink.value = true;
    try {
        const res = await fetch(`/chats/${props.chat.id}/invite-link`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': getCsrf(), Accept: 'application/json', 'Content-Type': 'application/json' },
        });
        inviteLink.value = (await res.json()).url;
    } catch { /* non-fatal */ }
    generatingLink.value = false;
}

async function copyLink() {
    await navigator.clipboard.writeText(inviteLink.value);
    linkCopied.value = true;
    setTimeout(() => { linkCopied.value = false; }, 2000);
}

// ── Helpers ────────────────────────────────────────────────────────────────
const contextColors: Record<string, { bg: string; text: string; dot: string }> = {
    relationship: { bg: 'bg-pink-50',    text: 'text-pink-600',    dot: 'bg-pink-400' },
    business:     { bg: 'bg-blue-50',    text: 'text-blue-600',    dot: 'bg-blue-400' },
    family:       { bg: 'bg-emerald-50', text: 'text-emerald-600', dot: 'bg-emerald-400' },
    financial:    { bg: 'bg-amber-50',   text: 'text-amber-600',   dot: 'bg-amber-400' },
    legal:        { bg: 'bg-violet-50',  text: 'text-violet-600',  dot: 'bg-violet-400' },
    general:      { bg: 'bg-gray-100',   text: 'text-gray-500',    dot: 'bg-gray-400' },
};

function contextStyle(type: string) { return contextColors[type] ?? contextColors.general; }
function formatTime(dateStr: string) { return new Date(dateStr).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }); }
function getInitials(name: string) { return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2); }
function chatTitle(chat: Chat) { return chat.title || `${chat.context_type.charAt(0).toUpperCase() + chat.context_type.slice(1)} Discussion`; }

const avatarColors = ['bg-rose-400', 'bg-violet-400', 'bg-teal-400', 'bg-orange-400'];
function avatarColor(id: number) { return avatarColors[id % avatarColors.length]; }
</script>

<template>
    <Head :title="chatTitle(chat)" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-col bg-white">

            <!-- ── Chat header ──────────────────────────────────────────── -->
            <div class="flex items-center justify-between border-b border-gray-100 bg-white px-3 py-2.5 sm:px-6 sm:py-3.5">
                <div class="flex min-w-0 items-center gap-2 sm:gap-3">
                    <span :class="['inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-xs font-medium capitalize sm:px-2.5', contextStyle(chat.context_type).bg, contextStyle(chat.context_type).text]">
                        <span :class="['h-1.5 w-1.5 rounded-full', contextStyle(chat.context_type).dot]" />
                        <span class="hidden sm:inline">{{ chat.context_type }}</span>
                    </span>
                    <div class="min-w-0">
                        <h1 class="truncate text-sm font-semibold text-gray-900">{{ chatTitle(chat) }}</h1>
                        <div class="hidden items-center gap-1 text-xs text-gray-400 sm:flex">
                            <Users class="h-3 w-3 flex-shrink-0" />
                            <span class="truncate">
                                {{ chat.participants.map(p => p.name).join(' · ') }}
                                <template v-if="chat.pending_invitations.length">
                                    · <span class="text-amber-500">{{ chat.pending_invitations.map(i => i.display).join(', ') }} (pending)</span>
                                </template>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Right side actions -->
                <div class="ml-2 flex flex-shrink-0 items-center gap-1.5 sm:ml-4 sm:gap-2">
                    <button
                        v-if="isCreator && chat.status !== 'finalized'"
                        @click="generateInviteLink"
                        :disabled="generatingLink || !!inviteLink"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-xs font-medium text-gray-600 transition hover:border-gray-300 hover:bg-gray-50 disabled:opacity-50 sm:px-3"
                    >
                        <Link2 class="h-3 w-3" />
                        <span class="hidden sm:inline">{{ generatingLink ? 'Generating…' : 'Invite Link' }}</span>
                    </button>

                    <span
                        v-if="chat.status === 'waiting'"
                        class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-600 ring-1 ring-amber-200 sm:px-3"
                    >
                        <Hourglass class="h-3 w-3" />
                        <span class="hidden sm:inline">Awaiting</span>
                    </span>
                    <span
                        v-else-if="chat.status === 'finalized'"
                        class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-500 sm:px-3"
                    >
                        <CheckCircle2 class="h-3 w-3 text-emerald-500" />
                        <span class="hidden sm:inline">Closed</span>
                    </span>
                    <button
                        v-else-if="isCreator && chat.status === 'active'"
                        @click="confirmingFinalize = true"
                        class="rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-xs font-medium text-gray-600 transition hover:border-gray-300 hover:bg-gray-50 sm:px-3"
                    >
                        <span class="hidden sm:inline">Close Session</span>
                        <X class="h-3.5 w-3.5 sm:hidden" />
                    </button>
                </div>
            </div>

            <!-- ── Waiting banner ───────────────────────────────────────── -->
            <div v-if="chat.status === 'waiting'" class="border-b border-amber-100 bg-amber-50 px-4 py-3 sm:px-6">
                <div class="flex items-start gap-2.5">
                    <Hourglass class="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-500" />
                    <p class="text-sm text-amber-700">
                        Waiting for <strong>{{ chat.pending_invitations.map(i => i.display).join(', ') }}</strong> to accept their invitation.
                        Messaging will unlock once everyone joins.
                    </p>
                </div>
            </div>

            <!-- ── Invite link banner ───────────────────────────────────── -->
            <div v-if="inviteLink" class="flex items-center gap-2 border-b border-blue-100 bg-blue-50 px-4 py-2.5 sm:gap-3 sm:px-6">
                <Link2 class="h-4 w-4 flex-shrink-0 text-blue-500" />
                <p class="min-w-0 flex-1 truncate text-xs text-blue-700">
                    <span class="hidden sm:inline">Share this link: </span>
                    <span class="font-mono font-medium">{{ inviteLink }}</span>
                </p>
                <button
                    @click="copyLink"
                    class="inline-flex flex-shrink-0 items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-blue-700"
                >
                    <Check v-if="linkCopied" class="h-3 w-3" />
                    <Copy v-else class="h-3 w-3" />
                    {{ linkCopied ? 'Copied!' : 'Copy' }}
                </button>
                <button @click="inviteLink = ''" class="text-blue-400 hover:text-blue-600">
                    <X class="h-4 w-4" />
                </button>
            </div>

            <!-- ── Messages ─────────────────────────────────────────────── -->
            <div class="flex-1 overflow-y-auto bg-gray-50 px-3 py-5 sm:px-6 sm:py-6">

                <!-- Empty state -->
                <div v-if="localMessages.length === 0 && chat.status === 'active'" class="flex h-full flex-col items-center justify-center text-center">
                    <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-900 shadow-lg">
                        <Sparkles class="h-6 w-6 text-white" />
                    </div>
                    <h3 class="mt-4 text-sm font-semibold text-gray-900">AccordAI is ready</h3>
                    <p class="mt-1.5 max-w-xs text-sm text-gray-500">
                        Start the conversation. Accord will join in naturally.
                    </p>
                </div>

                <div v-else-if="localMessages.length > 0" class="space-y-4 sm:space-y-5">
                    <div
                        v-for="message in localMessages"
                        :key="message.id"
                        :class="[
                            'flex items-end gap-2',
                            message.sender_type === 'user' && message.sender?.id === currentUser.id ? 'flex-row-reverse' : 'flex-row'
                        ]"
                    >
                        <!-- Avatar -->
                        <div class="flex-shrink-0 pb-1">
                            <div
                                v-if="message.sender_type === 'ai'"
                                class="flex h-7 w-7 items-center justify-center rounded-xl bg-gray-900 shadow-sm"
                            >
                                <Sparkles class="h-3.5 w-3.5 text-white" />
                            </div>
                            <div
                                v-else-if="message.sender?.id === currentUser.id"
                                class="flex h-7 w-7 items-center justify-center rounded-xl bg-gray-700 text-xs font-semibold text-white shadow-sm"
                            >
                                {{ getInitials(message.sender?.name ?? '?') }}
                            </div>
                            <div
                                v-else
                                :class="['flex h-7 w-7 items-center justify-center rounded-xl text-xs font-semibold text-white shadow-sm', avatarColor(message.sender?.id ?? 0)]"
                            >
                                {{ getInitials(message.sender?.name ?? '?') }}
                            </div>
                        </div>

                        <!-- Bubble group -->
                        <div
                            :class="[
                                'flex max-w-[85%] flex-col gap-1 sm:max-w-[68%]',
                                message.sender_type === 'user' && message.sender?.id === currentUser.id ? 'items-end' : 'items-start'
                            ]"
                        >
                            <!-- Sender + time label -->
                            <div class="flex items-center gap-1.5 px-1">
                                <span class="text-[11px] font-medium text-gray-400">
                                    <span v-if="message.sender_type === 'ai'" class="text-gray-500">Accord</span>
                                    <span v-else>{{ message.sender?.id === currentUser.id ? 'You' : message.sender?.name }}</span>
                                </span>
                                <span class="text-[11px] text-gray-300">·</span>
                                <span class="text-[11px] text-gray-400">{{ formatTime(message.created_at) }}</span>
                            </div>

                            <!-- Bubble -->
                            <div
                                :class="[
                                    'rounded-3xl px-4 py-2.5 text-sm leading-relaxed sm:py-3',
                                    message.sender_type === 'ai'
                                        ? 'rounded-bl-lg bg-white text-gray-800 shadow-sm ring-1 ring-gray-100'
                                        : message.sender?.id === currentUser.id
                                            ? 'rounded-br-lg bg-gray-900 text-white shadow-sm'
                                            : 'rounded-bl-lg bg-white text-gray-800 shadow-sm ring-1 ring-gray-100'
                                ]"
                            >
                                <p class="whitespace-pre-wrap">{{ message.content }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Typing indicator: other participants -->
                    <div v-if="typingUsers.length" class="flex items-end gap-2">
                        <div :class="['flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-xl text-xs font-semibold text-white shadow-sm', avatarColor(0)]">
                            {{ getInitials(typingUsers[0].name) }}
                        </div>
                        <div class="flex flex-col gap-1 items-start">
                            <span class="px-1 text-[11px] font-medium text-gray-400">
                                {{ typingUsers.map(u => u.name).join(', ') }}
                            </span>
                            <div class="rounded-3xl rounded-bl-lg bg-white px-4 py-3 shadow-sm ring-1 ring-gray-100">
                                <div class="flex items-center gap-1.5">
                                    <span class="h-2 w-2 animate-bounce rounded-full bg-gray-300 [animation-delay:-0.3s]" />
                                    <span class="h-2 w-2 animate-bounce rounded-full bg-gray-300 [animation-delay:-0.15s]" />
                                    <span class="h-2 w-2 animate-bounce rounded-full bg-gray-300" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- AI thinking animation (while waiting for AI after your message) -->
                    <div v-if="isAiThinking" class="flex items-end gap-2">
                        <div class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-xl bg-gray-900 shadow-sm">
                            <Sparkles class="h-3.5 w-3.5 text-white" />
                        </div>
                        <div class="rounded-3xl rounded-bl-lg bg-white px-5 py-3.5 shadow-sm ring-1 ring-gray-100">
                            <div class="flex items-center gap-1.5">
                                <span class="h-2 w-2 animate-bounce rounded-full bg-gray-300 [animation-delay:-0.3s]" />
                                <span class="h-2 w-2 animate-bounce rounded-full bg-gray-300 [animation-delay:-0.15s]" />
                                <span class="h-2 w-2 animate-bounce rounded-full bg-gray-300" />
                            </div>
                        </div>
                    </div>
                </div>

                <div ref="messagesEndRef" />
            </div>

            <!-- ── Input area ───────────────────────────────────────────── -->
            <div class="border-t border-gray-100 bg-white px-3 py-3 sm:px-6 sm:py-4">

                <div v-if="chat.status === 'waiting'" class="flex items-center gap-2 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-600">
                    <Hourglass class="h-4 w-4 flex-shrink-0" />
                    Messaging is disabled until all participants join.
                </div>

                <div v-else-if="chat.status === 'finalized'" class="flex items-center gap-2 rounded-2xl bg-gray-50 px-4 py-3 text-sm text-gray-500 ring-1 ring-gray-100">
                    <AlertCircle class="h-4 w-4 flex-shrink-0" />
                    This session is closed.
                </div>

                <div v-else class="flex items-end gap-2 sm:gap-3">
                    <div class="flex-1 rounded-2xl bg-gray-100 px-4 py-2.5 transition-all focus-within:bg-white focus-within:ring-2 focus-within:ring-gray-200">
                        <textarea
                            ref="textareaRef"
                            v-model="messageContent"
                            @keydown="handleKeydown"
                            @input="onInput"
                            placeholder="Type your message…"
                            rows="1"
                            :disabled="isSending"
                            class="w-full resize-none bg-transparent text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none"
                            style="field-sizing: content; max-height: 120px;"
                        />
                    </div>
                    <button
                        @click="sendMessage"
                        :disabled="!messageContent.trim() || isSending"
                        class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-gray-900 text-white shadow-sm transition hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-30"
                    >
                        <Send class="h-4 w-4" />
                    </button>
                </div>
                <p class="mt-2 text-center text-[11px] text-gray-300" v-if="chat.status === 'active'">
                    Enter to send · Shift+Enter for new line
                </p>
            </div>
        </div>

        <!-- ── Finalize confirmation modal ──────────────────────────────── -->
        <Teleport to="body">
            <div
                v-if="confirmingFinalize"
                class="fixed inset-0 z-50 flex items-end justify-center bg-black/20 p-4 backdrop-blur-sm sm:items-center"
                @click.self="confirmingFinalize = false"
            >
                <div class="w-full max-w-sm rounded-3xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start justify-between">
                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gray-100">
                            <Sparkles class="h-5 w-5 text-gray-700" />
                        </div>
                        <button @click="confirmingFinalize = false" class="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <h2 class="mt-4 text-base font-semibold text-gray-900">Close this session?</h2>
                    <p class="mt-1.5 text-sm text-gray-500">
                        AccordAI will analyze the conversation and extract behavioral insights for each participant to inform future sessions.
                    </p>
                    <div class="mt-5 flex flex-col gap-2">
                        <button
                            @click="finalizeChat"
                            class="w-full rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-gray-700"
                        >
                            Close & Extract Insights
                        </button>
                        <button
                            @click="confirmingFinalize = false"
                            class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
