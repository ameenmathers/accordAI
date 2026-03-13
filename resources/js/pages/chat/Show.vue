<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Send, AlertCircle, CheckCircle2, Hourglass, X, Sparkles, Link2, Copy, Check, CheckCheck, QrCode, Smile, Paperclip } from 'lucide-vue-next';
import { joinChatChannel, type EchoMessage } from '@/echo';
import QRCode from 'qrcode';

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
    initialInviteLink?: string | null;
    initialReadStatus?: Record<number, number>;
    initialSummary?: string | null;
    vapidPublicKey?: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Chats', href: '/chats' },
    { title: props.chat.title || `${props.chat.context_type} Discussion`, href: `/chats/${props.chat.id}` },
];

// ── Helpers ────────────────────────────────────────────────────────────────
function getCsrf(): string {
    return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
}

// ── Sound ──────────────────────────────────────────────────────────────────
function playSound() {
    try {
        const ctx = new (window.AudioContext || (window as any).webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.type = 'sine';
        osc.frequency.setValueAtTime(660, ctx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.08);
        gain.gain.setValueAtTime(0.15, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + 0.35);
    } catch { /* silent */ }
}

// ── Local chat status ──────────────────────────────────────────────────────
const localChatStatus = ref(props.chat.status);
watch(() => props.chat.status, (s) => { localChatStatus.value = s; });

// ── Local message state ─────────────────────────────────────────────────────
const localMessages = ref<Message[]>([...props.messages]);
const messagesEndRef = ref<HTMLDivElement | null>(null);
const textareaRef = ref<HTMLTextAreaElement | null>(null);
const isAiThinking = ref(false);

function scrollToBottom(force = false) {
    nextTick(() => {
        if (force || messagesEndRef.value) {
            messagesEndRef.value?.scrollIntoView({ behavior: 'smooth' });
        }
    });
}

const lastMessageId = computed(() => {
    const real = localMessages.value.filter(m => m.id > 0);
    return real.at(-1)?.id ?? 0;
});

function mergeMessages(incoming: Message[]) {
    const existingRealIds = new Set(localMessages.value.filter(m => m.id > 0).map(m => m.id));
    const fresh = incoming.filter(m => !existingRealIds.has(m.id));
    if (!fresh.length) return;

    for (const real of fresh) {
        if (real.sender_type === 'user' && real.sender?.id === props.currentUser.id) {
            const idx = localMessages.value.findIndex(
                m => m.id < 0 && m.sender_type === 'user' && m.content === real.content && m.sender?.id === real.sender?.id
            );
            if (idx !== -1) localMessages.value.splice(idx, 1);
        } else if (real.sender_type === 'ai') {
            const idx = localMessages.value.findIndex(m => m.id < 0 && m.sender_type === 'ai');
            if (idx !== -1) localMessages.value.splice(idx, 1);
        }
    }

    if (fresh.some(m => m.sender_type === 'ai')) isAiThinking.value = false;

    for (const msg of fresh) {
        if (msg.sender_type === 'ai') {
            playSound();
            showNotification('Accord', msg.content);
        } else if (msg.sender?.id !== props.currentUser.id) {
            playSound();
            showNotification(msg.sender?.name ?? 'New message', msg.content);
        }
    }

    localMessages.value.push(...fresh);
    scrollToBottom();
}

watch(() => props.messages, mergeMessages, { deep: true });

onMounted(() => scrollToBottom(true));

// ── Desktop + background push notifications ─────────────────────────────────
async function requestNotifyPermission() {
    if (!('Notification' in window)) return;
    if (Notification.permission === 'default') {
        await Notification.requestPermission();
    }
    if (Notification.permission === 'granted' && props.vapidPublicKey && 'serviceWorker' in navigator) {
        subscribeToPush();
    }
}

function urlBase64ToUint8Array(base64String: string): Uint8Array {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    return Uint8Array.from([...rawData].map(c => c.charCodeAt(0)));
}

async function subscribeToPush() {
    try {
        const reg = await navigator.serviceWorker.ready;
        let sub = await reg.pushManager.getSubscription();
        if (!sub) {
            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(props.vapidPublicKey!),
            });
        }
        const json = sub.toJSON();
        await fetch('/push/subscribe', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': getCsrf(), 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                endpoint: sub.endpoint,
                p256dh_key: json.keys?.p256dh,
                auth_key: json.keys?.auth,
            }),
        });
    } catch { /* non-fatal */ }
}

function showNotification(title: string, body: string) {
    if ('Notification' in window && Notification.permission === 'granted' && document.hidden) {
        const n = new Notification(title, {
            body: body.slice(0, 120),
            icon: '/favicon.ico',
            tag: `accord-${props.chat.id}`,
        });
        n.onclick = () => { window.focus(); n.close(); };
    }
}

// ── Sending messages ───────────────────────────────────────────────────────
const messageContent = ref('');
const isSending = ref(false);

async function sendMessage() {
    const content = messageContent.value.trim();
    if (!content || isSending.value || localChatStatus.value !== 'active') return;

    messageContent.value = '';
    isAiThinking.value = true;

    const tempId = -Date.now();
    localMessages.value.push({
        id: tempId,
        sender_type: 'user',
        sender: { id: props.currentUser.id, name: props.currentUser.name },
        content,
        created_at: new Date().toISOString(),
    });
    scrollToBottom();

    isSending.value = true;

    const aiTempId = -(Date.now() + 1);
    let streamingAiAdded = false;

    try {
        const res = await fetch(`/chats/${props.chat.id}/messages`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': getCsrf(),
                'Accept': 'text/event-stream',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ content }),
        });

        isSending.value = false;
        nextTick(() => textareaRef.value?.focus());

        if (!res.ok) {
            localMessages.value = localMessages.value.filter(m => m.id !== tempId);
            messageContent.value = content;
            isAiThinking.value = false;
            return;
        }

        if (res.headers.get('content-type')?.includes('text/event-stream') && res.body) {
            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let aiContent = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() ?? '';

                for (const line of lines) {
                    if (!line.startsWith('data: ')) continue;
                    const data = line.slice(6).trim();
                    if (data === '[DONE]') {
                        isAiThinking.value = false;
                        if (streamingAiAdded) playSound();
                        break;
                    }
                    try {
                        const { token } = JSON.parse(data) as { token?: string };
                        if (token) {
                            aiContent += token;
                            if (!streamingAiAdded) {
                                localMessages.value.push({
                                    id: aiTempId,
                                    sender_type: 'ai',
                                    sender: null,
                                    content: aiContent,
                                    created_at: new Date().toISOString(),
                                });
                                streamingAiAdded = true;
                                isAiThinking.value = false;
                            } else {
                                const idx = localMessages.value.findIndex(m => m.id === aiTempId);
                                if (idx !== -1) localMessages.value[idx] = { ...localMessages.value[idx], content: aiContent };
                            }
                            scrollToBottom();
                        }
                    } catch { /* json parse — skip */ }
                }
            }
        } else {
            isAiThinking.value = false;
        }
    } catch {
        localMessages.value = localMessages.value.filter(m => m.id !== tempId && m.id !== aiTempId);
        messageContent.value = content;
        isAiThinking.value = false;
        isSending.value = false;
    }
}

function handleKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
}

// ── Live polling ───────────────────────────────────────────────────────────
let pollTimer: ReturnType<typeof setInterval> | null = null;

async function pollMessages() {
    try {
        const res = await fetch(`/chats/${props.chat.id}/messages?after=${lastMessageId.value}`, {
            headers: { Accept: 'application/json' },
        });
        if (res.ok) {
            const data = await res.json();
            if (Array.isArray(data)) {
                mergeMessages(data);
            } else {
                mergeMessages(data.messages ?? []);
                if (data.readStatus) readStatus.value = { ...readStatus.value, ...data.readStatus };
            }
        }
    } catch { /* silent */ }
}

// ── Typing indicators ──────────────────────────────────────────────────────
const typingUsers = ref<{ name: string }[]>([]);
let typingTimer: ReturnType<typeof setInterval> | null = null;
let lastTypedAt = 0;

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

// ── Read receipts ──────────────────────────────────────────────────────────
const readStatus = ref<Record<number, number>>(props.initialReadStatus ?? {});

function isReadByAll(message: Message): boolean {
    if (message.sender_type !== 'user' || message.sender?.id !== props.currentUser.id) return false;
    const others = props.chat.participants.filter(p => p.id !== props.currentUser.id);
    if (others.length === 0) return false;
    return others.every(p => (readStatus.value[p.id] ?? 0) >= message.id);
}

// ── Participant joined banner ───────────────────────────────────────────────
const participantJoinedName = ref<string | null>(null);
let bannerTimer: ReturnType<typeof setTimeout> | null = null;

function showJoinedBanner(name: string) {
    participantJoinedName.value = name;
    if (bannerTimer) clearTimeout(bannerTimer);
    bannerTimer = setTimeout(() => { participantJoinedName.value = null; }, 5000);
}

// ── Online status ──────────────────────────────────────────────────────────
const onlineUserIds = ref<number[]>([]);
let heartbeatTimer: ReturnType<typeof setInterval> | null = null;
let onlineTimer: ReturnType<typeof setInterval> | null = null;

function sendHeartbeat() {
    fetch(`/chats/${props.chat.id}/heartbeat`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': getCsrf(), Accept: 'application/json', 'Content-Type': 'application/json' },
    }).catch(() => {});
}

async function pollOnline() {
    try {
        const res = await fetch(`/chats/${props.chat.id}/online`, { headers: { Accept: 'application/json' } });
        if (res.ok) onlineUserIds.value = await res.json();
    } catch { /* silent */ }
}

// ── Session summary ──────────────────────────────────────────────────────
const showSummary = ref(!!props.initialSummary);
const sessionSummary = ref(props.initialSummary ?? '');

// ── Echo WebSocket channel ──────────────────────────────────────────────────
let echoChannel: any = null;

onMounted(() => {
    if (localChatStatus.value !== 'finalized') {
        try {
            echoChannel = joinChatChannel(
                props.chat.id,
                props.currentUser.id,
                (msg: EchoMessage) => {
                    if (msg.sender_type === 'user' && msg.sender?.id === props.currentUser.id) return;
                    mergeMessages([msg]);
                },
                (data: { userId: number; userName: string }) => {
                    typingUsers.value = [{ name: data.userName }];
                    setTimeout(() => { typingUsers.value = typingUsers.value.filter(u => u.name !== data.userName); }, 3000);
                },
                (data: { userId: number; userName: string }) => {
                    showJoinedBanner(data.userName);
                    localChatStatus.value = 'active';
                    router.reload({ only: ['chat'] });
                },
            );
        } catch {
            // Echo not available — fall back to polling
        }

        pollTimer = setInterval(pollMessages, 5000);
    }
    if (localChatStatus.value === 'active') {
        typingTimer = setInterval(pollTyping, 1500);
    }
    sendHeartbeat();
    pollOnline();
    heartbeatTimer = setInterval(sendHeartbeat, 30000);
    onlineTimer = setInterval(pollOnline, 10000);

    requestNotifyPermission();
});

onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer);
    if (typingTimer) clearInterval(typingTimer);
    if (heartbeatTimer) clearInterval(heartbeatTimer);
    if (onlineTimer) clearInterval(onlineTimer);
    if (bannerTimer) clearTimeout(bannerTimer);
    if (echoChannel) echoChannel.leave?.();
});

// ── Finalize ───────────────────────────────────────────────────────────────
const confirmingFinalize = ref(false);
const isCreator = props.currentUser.id === props.chat.created_by.id;

function finalizeChat() {
    router.post(`/chats/${props.chat.id}/finalize`, {}, {
        onSuccess: () => { confirmingFinalize.value = false; },
    });
}

// ── Invite link ─────────────────────────────────────────────────────────────
const inviteLink = ref<string>(props.initialInviteLink ?? '');
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

// ── QR code ──────────────────────────────────────────────────────────────
const showQr = ref(false);
const qrDataUrl = ref('');

async function openQr() {
    if (!inviteLink.value) return;
    qrDataUrl.value = await QRCode.toDataURL(inviteLink.value, { width: 280, margin: 2 });
    showQr.value = true;
}

// ── Display helpers ─────────────────────────────────────────────────────────
function formatTime(dateStr: string) { return new Date(dateStr).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }); }
function getInitials(name: string) { return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2); }
function chatTitle(chat: Chat) { return chat.title || `${chat.context_type.charAt(0).toUpperCase() + chat.context_type.slice(1)} Discussion`; }

const avatarPalette = ['bg-rose-400', 'bg-violet-400', 'bg-teal-400', 'bg-orange-400', 'bg-blue-400'];
function avatarColor(id: number) { return avatarPalette[id % avatarPalette.length]; }

// The "other" participant to show in the header (first non-current participant, or AI if solo)
const headerParticipant = computed(() => {
    return props.chat.participants.find(p => p.id !== props.currentUser.id) ?? null;
});
const headerOnline = computed(() => {
    return headerParticipant.value ? onlineUserIds.value.includes(headerParticipant.value.id) : false;
});
</script>

<template>
    <Head :title="chatTitle(chat)" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-col bg-white">

            <!-- ── Chat header ──────────────────────────────────────────── -->
            <div class="flex items-center justify-between border-b border-gray-100 bg-white px-4 py-3 sm:px-6">
                <!-- Left: avatar + name + status -->
                <div class="flex min-w-0 items-center gap-3">
                    <!-- Avatar stack -->
                    <div class="relative flex-shrink-0">
                        <div
                            v-if="headerParticipant"
                            :class="['flex h-10 w-10 items-center justify-center rounded-2xl text-sm font-bold text-white shadow-sm', avatarColor(headerParticipant.id)]"
                        >
                            {{ getInitials(headerParticipant.name) }}
                        </div>
                        <div v-else class="flex h-10 w-10 items-center justify-center rounded-2xl bg-violet-100 shadow-sm">
                            <Sparkles class="h-5 w-5 text-violet-500" />
                        </div>
                        <!-- Online dot -->
                        <span
                            v-if="headerOnline"
                            class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white bg-emerald-400"
                        />
                    </div>

                    <div class="min-w-0">
                        <div class="flex items-center gap-1.5">
                            <h1 class="truncate text-sm font-bold text-gray-900">
                                {{ headerParticipant ? headerParticipant.name : chatTitle(chat) }}
                            </h1>
                            <span v-if="headerOnline" class="h-2 w-2 flex-shrink-0 rounded-full bg-emerald-400" />
                        </div>
                        <p class="truncate text-xs text-gray-400">
                            <template v-if="localChatStatus === 'waiting'">Waiting for participants</template>
                            <template v-else-if="localChatStatus === 'finalized'">Session closed</template>
                            <template v-else>
                                {{ chat.participants.map(p => p.id === currentUser.id ? 'You' : p.name).join(', ') }}
                                · AI Mediation
                            </template>
                        </p>
                    </div>
                </div>

                <!-- Right: action buttons -->
                <div class="ml-2 flex flex-shrink-0 items-center gap-1.5">
                    <button
                        v-if="isCreator && chat.status !== 'finalized'"
                        @click="generateInviteLink"
                        :disabled="generatingLink || !!inviteLink"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-blue-500 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-blue-600 disabled:opacity-50"
                    >
                        <Link2 class="h-3.5 w-3.5" />
                        <span class="hidden sm:inline">{{ generatingLink ? 'Generating…' : 'Invite' }}</span>
                    </button>

                    <span
                        v-if="localChatStatus === 'waiting'"
                        class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-600 ring-1 ring-amber-200"
                    >
                        <Hourglass class="h-3 w-3" />
                        <span class="hidden sm:inline">Waiting</span>
                    </span>
                    <span
                        v-else-if="localChatStatus === 'finalized'"
                        class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-500"
                    >
                        <CheckCircle2 class="h-3 w-3 text-emerald-500" />
                        <span class="hidden sm:inline">Closed</span>
                    </span>
                    <button
                        v-else-if="isCreator && localChatStatus === 'active'"
                        @click="confirmingFinalize = true"
                        class="rounded-xl border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600 transition hover:border-gray-300 hover:bg-gray-50"
                    >
                        <span class="hidden sm:inline">Close Session</span>
                        <X class="h-3.5 w-3.5 sm:hidden" />
                    </button>
                </div>
            </div>

            <!-- ── Waiting banner ───────────────────────────────────────── -->
            <div v-if="localChatStatus === 'waiting'" class="border-b border-amber-100 bg-amber-50 px-4 py-2.5 sm:px-6">
                <div class="flex items-center gap-2">
                    <Hourglass class="h-3.5 w-3.5 flex-shrink-0 text-amber-500" />
                    <p class="text-xs text-amber-700">
                        Waiting for <strong>{{ chat.pending_invitations.map(i => i.display).join(', ') }}</strong> to accept their invitation.
                    </p>
                </div>
            </div>

            <!-- ── Invite link banner ───────────────────────────────────── -->
            <div v-if="inviteLink" class="flex items-center gap-2 border-b border-blue-100 bg-blue-50 px-4 py-2 sm:gap-3 sm:px-6">
                <Link2 class="h-3.5 w-3.5 flex-shrink-0 text-blue-500" />
                <p class="min-w-0 flex-1 truncate text-xs text-blue-700">
                    <span class="hidden sm:inline">Share: </span>
                    <span class="font-mono font-medium">{{ inviteLink }}</span>
                </p>
                <button
                    @click="copyLink"
                    class="inline-flex flex-shrink-0 items-center gap-1 rounded-lg bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-blue-700"
                >
                    <Check v-if="linkCopied" class="h-3 w-3" />
                    <Copy v-else class="h-3 w-3" />
                    {{ linkCopied ? 'Copied!' : 'Copy' }}
                </button>
                <button
                    @click="openQr"
                    class="inline-flex flex-shrink-0 items-center gap-1 rounded-lg border border-blue-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-blue-600 transition hover:bg-blue-50"
                    title="Show QR code"
                >
                    <QrCode class="h-3 w-3" />
                    <span class="hidden sm:inline">QR</span>
                </button>
                <button @click="inviteLink = ''" class="text-blue-400 hover:text-blue-600">
                    <X class="h-4 w-4" />
                </button>
            </div>

            <!-- ── Participant joined banner ────────────────────────────── -->
            <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="-translate-y-2 opacity-0" enter-to-class="translate-y-0 opacity-100" leave-active-class="transition duration-200 ease-in" leave-from-class="translate-y-0 opacity-100" leave-to-class="-translate-y-2 opacity-0">
                <div v-if="participantJoinedName" class="flex items-center gap-2 border-b border-emerald-100 bg-emerald-50 px-4 py-2.5 sm:px-6">
                    <span class="h-2 w-2 rounded-full bg-emerald-400" />
                    <p class="text-sm font-medium text-emerald-700">{{ participantJoinedName }} just joined the session!</p>
                    <button @click="participantJoinedName = null" class="ml-auto text-emerald-400 hover:text-emerald-600">
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>
            </Transition>

            <!-- ── Messages ─────────────────────────────────────────────── -->
            <div class="flex-1 overflow-y-auto bg-white px-4 py-5 sm:px-6 sm:py-6">

                <!-- Empty state -->
                <div v-if="localMessages.length === 0 && localChatStatus === 'active'" class="flex h-full flex-col items-center justify-center text-center">
                    <div class="flex h-14 w-14 items-center justify-center rounded-3xl bg-violet-100 shadow-inner">
                        <Sparkles class="h-6 w-6 text-violet-500" />
                    </div>
                    <h3 class="mt-4 text-sm font-semibold text-gray-900">Accord is ready</h3>
                    <p class="mt-1.5 max-w-xs text-sm text-gray-400">
                        Say something to get started. Accord will guide the conversation.
                    </p>
                </div>

                <div v-else-if="localMessages.length > 0 || isAiThinking" class="space-y-3">
                    <div
                        v-for="message in localMessages"
                        :key="message.id"
                        :class="[
                            'flex items-end gap-2',
                            message.sender_type === 'user' && message.sender?.id === currentUser.id ? 'flex-row-reverse' : 'flex-row'
                        ]"
                    >
                        <!-- Avatar -->
                        <div class="flex-shrink-0 pb-5">
                            <div
                                v-if="message.sender_type === 'ai'"
                                class="flex h-8 w-8 items-center justify-center rounded-2xl bg-violet-100 shadow-sm"
                            >
                                <Sparkles class="h-4 w-4 text-violet-500" />
                            </div>
                            <div
                                v-else-if="message.sender?.id === currentUser.id"
                                class="flex h-8 w-8 items-center justify-center rounded-2xl bg-gray-200 text-xs font-bold text-gray-600 shadow-sm"
                            >
                                {{ getInitials(message.sender?.name ?? '?') }}
                            </div>
                            <div
                                v-else
                                :class="['flex h-8 w-8 items-center justify-center rounded-2xl text-xs font-bold text-white shadow-sm', avatarColor(message.sender?.id ?? 0)]"
                            >
                                {{ getInitials(message.sender?.name ?? '?') }}
                            </div>
                        </div>

                        <!-- Bubble -->
                        <div
                            :class="[
                                'flex max-w-[80%] flex-col gap-1 sm:max-w-[65%]',
                                message.sender_type === 'user' && message.sender?.id === currentUser.id ? 'items-end' : 'items-start'
                            ]"
                        >
                            <!-- Sender label + time -->
                            <div class="flex items-center gap-1.5 px-1">
                                <span class="text-[11px] font-medium text-gray-400">
                                    <span v-if="message.sender_type === 'ai'">Accord</span>
                                    <span v-else>{{ message.sender?.id === currentUser.id ? 'You' : message.sender?.name }}</span>
                                </span>
                                <span class="text-[11px] text-gray-300">·</span>
                                <span class="text-[11px] text-gray-400">{{ formatTime(message.created_at) }}</span>
                                <span v-if="message.id < 0" class="text-[11px] text-gray-300">sending…</span>
                                <CheckCheck
                                    v-else-if="message.sender?.id === currentUser.id && isReadByAll(message)"
                                    class="h-3 w-3 text-blue-400"
                                    title="Read"
                                />
                                <Check
                                    v-else-if="message.sender?.id === currentUser.id && message.id > 0"
                                    class="h-3 w-3 text-gray-300"
                                    title="Delivered"
                                />
                            </div>

                            <!-- Message bubble -->
                            <div
                                :class="[
                                    'rounded-3xl px-4 py-2.5 text-sm leading-relaxed',
                                    message.sender_type === 'ai'
                                        ? 'rounded-bl-md bg-violet-100 text-gray-800'
                                        : message.sender?.id === currentUser.id
                                            ? 'rounded-br-md bg-white text-gray-900 shadow-sm ring-1 ring-gray-100'
                                            : 'rounded-bl-md bg-violet-100 text-gray-800',
                                    message.id < 0 ? 'opacity-60' : ''
                                ]"
                            >
                                <p class="whitespace-pre-wrap">{{ message.content }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Typing indicator -->
                    <div v-if="typingUsers.length" class="flex items-end gap-2">
                        <div :class="['flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-2xl text-xs font-bold text-white shadow-sm', avatarColor(0)]">
                            {{ getInitials(typingUsers[0].name) }}
                        </div>
                        <div class="flex flex-col gap-1 items-start">
                            <span class="px-1 text-[11px] font-medium text-gray-400">{{ typingUsers.map(u => u.name).join(', ') }} is typing</span>
                            <div class="rounded-3xl rounded-bl-md bg-violet-100 px-4 py-3">
                                <div class="flex items-center gap-1.5">
                                    <span class="h-2 w-2 animate-bounce rounded-full bg-violet-400 [animation-delay:-0.3s]" />
                                    <span class="h-2 w-2 animate-bounce rounded-full bg-violet-400 [animation-delay:-0.15s]" />
                                    <span class="h-2 w-2 animate-bounce rounded-full bg-violet-400" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- AI thinking -->
                    <div v-if="isAiThinking" class="flex items-end gap-2">
                        <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-2xl bg-violet-100 shadow-sm">
                            <Sparkles class="h-4 w-4 text-violet-500" />
                        </div>
                        <div class="flex flex-col gap-1 items-start">
                            <span class="px-1 text-[11px] font-medium text-gray-400">Accord</span>
                            <div class="rounded-3xl rounded-bl-md bg-violet-100 px-5 py-3.5">
                                <div class="flex items-center gap-1.5">
                                    <span class="h-2 w-2 animate-bounce rounded-full bg-violet-400 [animation-delay:-0.3s]" />
                                    <span class="h-2 w-2 animate-bounce rounded-full bg-violet-400 [animation-delay:-0.15s]" />
                                    <span class="h-2 w-2 animate-bounce rounded-full bg-violet-400" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div ref="messagesEndRef" />
            </div>

            <!-- ── Input area ───────────────────────────────────────────── -->
            <div class="border-t border-gray-100 bg-white px-4 py-3 sm:px-6 sm:py-4">

                <div v-if="localChatStatus === 'waiting'" class="flex items-center gap-2 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-600">
                    <Hourglass class="h-4 w-4 flex-shrink-0" />
                    Messaging is disabled until all participants join.
                </div>

                <div v-else-if="localChatStatus === 'finalized'" class="flex items-center gap-2 rounded-2xl bg-gray-50 px-4 py-3 text-sm text-gray-500 ring-1 ring-gray-100">
                    <AlertCircle class="h-4 w-4 flex-shrink-0" />
                    This session is closed.
                </div>

                <div v-else class="flex items-end gap-2">
                    <!-- Decorative icon buttons -->
                    <div class="flex flex-shrink-0 items-center gap-1 pb-0.5">
                        <button class="flex h-8 w-8 items-center justify-center rounded-xl text-gray-300 transition hover:bg-gray-100 hover:text-gray-500" title="Attachment">
                            <Paperclip class="h-4 w-4" />
                        </button>
                        <button class="flex h-8 w-8 items-center justify-center rounded-xl text-gray-300 transition hover:bg-gray-100 hover:text-gray-500" title="Emoji">
                            <Smile class="h-4 w-4" />
                        </button>
                    </div>

                    <!-- Text input -->
                    <div class="flex-1 rounded-2xl border border-gray-200 bg-gray-50 px-4 py-2.5 transition-all focus-within:border-violet-200 focus-within:bg-white focus-within:ring-2 focus-within:ring-violet-100">
                        <textarea
                            ref="textareaRef"
                            v-model="messageContent"
                            @keydown="handleKeydown"
                            @input="onInput"
                            placeholder="Message…"
                            rows="1"
                            :disabled="isSending"
                            class="w-full resize-none bg-transparent text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none"
                            style="field-sizing: content; max-height: 120px;"
                        />
                    </div>

                    <!-- Send button -->
                    <button
                        @click="sendMessage"
                        :disabled="!messageContent.trim() || isSending"
                        class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-2xl bg-violet-600 text-white shadow-sm shadow-violet-200 transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-30"
                    >
                        <Send class="h-4 w-4" />
                    </button>
                </div>

                <p v-if="localChatStatus === 'active'" class="mt-2 text-center text-[11px] text-gray-300">
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
                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-violet-50">
                            <Sparkles class="h-5 w-5 text-violet-500" />
                        </div>
                        <button @click="confirmingFinalize = false" class="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <h2 class="mt-4 text-base font-semibold text-gray-900">Close this session?</h2>
                    <p class="mt-1.5 text-sm text-gray-500">
                        AccordAI will analyze the conversation and extract behavioral insights to inform future sessions.
                    </p>
                    <div class="mt-5 flex flex-col gap-2">
                        <button
                            @click="finalizeChat"
                            class="w-full rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700"
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

        <!-- ── QR code modal ────────────────────────────────────────────── -->
        <Teleport to="body">
            <div
                v-if="showQr"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4 backdrop-blur-sm"
                @click.self="showQr = false"
            >
                <div class="w-full max-w-xs rounded-3xl bg-white p-6 shadow-2xl text-center">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-sm font-semibold text-gray-900">Scan to join</h2>
                        <button @click="showQr = false" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <img v-if="qrDataUrl" :src="qrDataUrl" alt="QR code" class="mx-auto rounded-2xl" />
                    <p class="mt-3 text-xs text-gray-400">Share this QR code — scanning it opens the invitation link.</p>
                </div>
            </div>
        </Teleport>

        <!-- ── Session summary modal ─────────────────────────────────────── -->
        <Teleport to="body">
            <div
                v-if="showSummary && sessionSummary"
                class="fixed inset-0 z-50 flex items-end justify-center bg-black/30 p-4 backdrop-blur-sm sm:items-center"
            >
                <div class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start justify-between">
                        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-2xl bg-emerald-50">
                            <CheckCircle2 class="h-5 w-5 text-emerald-500" />
                        </div>
                        <button @click="showSummary = false" class="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <h2 class="mt-4 text-base font-semibold text-gray-900">Session Summary</h2>
                    <p class="mt-0.5 text-xs text-gray-400 capitalize">{{ chat.context_type }} session · {{ chat.title ?? 'Untitled' }}</p>
                    <div class="mt-4 rounded-2xl bg-gray-50 px-5 py-4">
                        <p class="whitespace-pre-wrap text-sm leading-relaxed text-gray-700">{{ sessionSummary }}</p>
                    </div>
                    <p class="mt-3 text-xs text-gray-400">
                        Accord has extracted behavioral insights from this session to improve future mediation.
                    </p>
                    <button
                        @click="showSummary = false"
                        class="mt-5 w-full rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700"
                    >
                        Done
                    </button>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
