<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { MessageSquare, Plus, CheckCircle, Hourglass, X, Sparkles, Search, UserPlus, Link2 } from 'lucide-vue-next';

// ── Types ──────────────────────────────────────────────────────────────────
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
interface UserResult { id: number; name: string; username: string }

const props = defineProps<{ chats: Chat[]; contextTypes: ContextType[]; onlineUserIds?: number[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Chats', href: '/chats' },
];

// ── Session search ─────────────────────────────────────────────────────────
const searchQuery = ref('');
const filteredChats = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return props.chats;
    return props.chats.filter(c =>
        (c.title ?? '').toLowerCase().includes(q) ||
        c.context_type.toLowerCase().includes(q) ||
        c.participants.some(p => p.name.toLowerCase().includes(q))
    );
});

// ── Create chat modal ──────────────────────────────────────────────────────
const showCreateModal = ref(false);

const useInviteLink = ref(false);

const form = useForm({
    context_type: 'general',
    title: '',
    invitee_usernames: [] as string[],
    use_invite_link: false,
});

// ── Session templates ──────────────────────────────────────────────────────
const templates: { label: string; emoji: string; context_type: string; title: string }[] = [
    { label: 'Budget talk', emoji: '💰', context_type: 'financial', title: 'Budget planning discussion' },
    { label: 'Work conflict', emoji: '💼', context_type: 'business', title: 'Team conflict resolution' },
    { label: 'Relationship', emoji: '❤️', context_type: 'relationship', title: 'Relationship discussion' },
    { label: 'Family issue', emoji: '🏠', context_type: 'family', title: 'Family conflict' },
];

function applyTemplate(t: typeof templates[0]) {
    form.context_type = t.context_type;
    form.title = t.title;
}

function createChat() {
    form.use_invite_link = useInviteLink.value;
    if (useInviteLink.value) form.invitee_usernames = [];
    form.post('/chats', {
        onSuccess: () => {
            showCreateModal.value = false;
            form.reset();
            form.invitee_usernames = [];
            selectedUsers.value = [];
            userSearch.value = '';
            useInviteLink.value = false;
        },
    });
}

// ── Username search ────────────────────────────────────────────────────────
const userSearch = ref('');
const userResults = ref<UserResult[]>([]);
const searchLoading = ref(false);
const selectedUsers = ref<UserResult[]>([]);
let searchTimer: ReturnType<typeof setTimeout>;

watch(userSearch, (q) => {
    clearTimeout(searchTimer);
    if (q.trim().length < 1) { userResults.value = []; return; }
    searchLoading.value = true;
    searchTimer = setTimeout(async () => {
        try {
            const res = await fetch(`/users/search?q=${encodeURIComponent(q)}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json();
            userResults.value = data.filter((u: UserResult) => !selectedUsers.value.find(s => s.id === u.id));
        } catch { userResults.value = []; }
        searchLoading.value = false;
    }, 250);
});

function selectUser(user: UserResult) {
    if (selectedUsers.value.length >= 2) return;
    selectedUsers.value.push(user);
    form.invitee_usernames = selectedUsers.value.map(u => u.username);
    userSearch.value = '';
    userResults.value = [];
}

function removeUser(id: number) {
    selectedUsers.value = selectedUsers.value.filter(u => u.id !== id);
    form.invitee_usernames = selectedUsers.value.map(u => u.username);
}

// ── Helpers ────────────────────────────────────────────────────────────────
const avatarPalette = [
    'bg-violet-400', 'bg-rose-400', 'bg-blue-400',
    'bg-emerald-400', 'bg-orange-400', 'bg-pink-400', 'bg-teal-400',
];

function chatAvatarColor(chatId: number): string {
    return avatarPalette[chatId % avatarPalette.length];
}

function chatInitials(chat: Chat): string {
    const title = chatDisplayTitle(chat);
    return title.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
}

function formatDate(dateStr: string) {
    const d = new Date(dateStr);
    const now = new Date();
    const diffMs = now.getTime() - d.getTime();
    const diffDays = Math.floor(diffMs / 86400000);
    if (diffDays === 0) return d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    if (diffDays === 1) return 'Yesterday';
    if (diffDays < 7) return d.toLocaleDateString('en-US', { weekday: 'short' });
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function chatDisplayTitle(chat: Chat): string {
    return chat.title || `${chat.context_type.charAt(0).toUpperCase() + chat.context_type.slice(1)} Discussion`;
}

function truncate(text: string, max = 45): string {
    return text.length > max ? text.slice(0, max) + '…' : text;
}
</script>

<template>
    <Head title="Messages" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="relative flex h-full flex-col bg-white">

            <!-- ── Header ───────────────────────────────────────────────── -->
            <div class="border-b border-gray-100 px-5 pb-3 pt-5">
                <div class="flex items-center justify-between">
                    <h1 class="text-2xl font-bold text-gray-900">Messages</h1>
                    <button
                        @click="showCreateModal = true"
                        class="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-600 text-white shadow-sm transition hover:bg-violet-700"
                    >
                        <Plus class="h-4 w-4" />
                    </button>
                </div>

                <!-- Search -->
                <div v-if="chats.length > 0" class="relative mt-3">
                    <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-300" />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search conversations…"
                        class="block w-full rounded-2xl border-0 bg-gray-100 py-2.5 pl-9 pr-4 text-sm text-gray-900 placeholder:text-gray-400 focus:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-violet-200 transition"
                    />
                </div>
            </div>

            <!-- ── Empty state ───────────────────────────────────────────── -->
            <div v-if="chats.length === 0" class="flex flex-1 flex-col items-center justify-center py-20 text-center">
                <div class="flex h-16 w-16 items-center justify-center rounded-3xl bg-violet-50">
                    <MessageSquare class="h-7 w-7 text-violet-400" />
                </div>
                <h3 class="mt-4 text-base font-semibold text-gray-900">No conversations yet</h3>
                <p class="mt-1.5 max-w-xs text-sm text-gray-400">
                    Start your first mediation session and invite a participant.
                </p>
                <button
                    @click="showCreateModal = true"
                    class="mt-6 inline-flex items-center gap-2 rounded-2xl bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-violet-200 transition hover:bg-violet-700"
                >
                    <Plus class="h-4 w-4" />
                    Start new chat
                </button>
            </div>

            <!-- ── No search results ─────────────────────────────────────── -->
            <div v-else-if="filteredChats.length === 0" class="flex flex-1 flex-col items-center justify-center py-16 text-center">
                <p class="text-sm text-gray-400">No results for "{{ searchQuery }}"</p>
                <button @click="searchQuery = ''" class="mt-2 text-xs font-medium text-violet-500 underline underline-offset-2">Clear search</button>
            </div>

            <!-- ── Chat list ─────────────────────────────────────────────── -->
            <div v-else class="flex-1 overflow-y-auto pb-24">
                <ul class="divide-y divide-gray-50 py-1">
                    <li v-for="chat in filteredChats" :key="chat.id">
                        <Link
                            :href="`/chats/${chat.id}`"
                            class="flex items-center gap-3.5 px-5 py-3.5 transition hover:bg-gray-50 active:bg-gray-100"
                        >
                            <!-- Avatar with online/status dot -->
                            <div class="relative flex-shrink-0">
                                <div :class="['flex h-12 w-12 items-center justify-center rounded-2xl text-sm font-bold text-white shadow-sm', chatAvatarColor(chat.id)]">
                                    {{ chatInitials(chat) }}
                                </div>
                                <span
                                    v-if="chat.status === 'active'"
                                    class="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full border-2 border-white bg-emerald-400"
                                />
                                <span
                                    v-else-if="chat.status === 'waiting'"
                                    class="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full border-2 border-white bg-amber-400"
                                />
                            </div>

                            <!-- Content -->
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-2">
                                    <span :class="['truncate text-[15px]', chat.unread_count > 0 ? 'font-bold text-gray-900' : 'font-semibold text-gray-700']">
                                        {{ chatDisplayTitle(chat) }}
                                    </span>
                                    <span class="flex-shrink-0 text-xs text-gray-400">{{ formatDate(chat.updated_at) }}</span>
                                </div>
                                <div class="mt-0.5 flex items-center justify-between gap-2">
                                    <p :class="['truncate text-sm', chat.unread_count > 0 ? 'font-medium text-gray-600' : 'text-gray-400']">
                                        <template v-if="chat.last_message">
                                            <span v-if="chat.last_message.sender_type === 'ai'" class="text-violet-500">Accord: </span>
                                            {{ truncate(chat.last_message.content) }}
                                        </template>
                                        <template v-else>
                                            {{ chat.participants.map(p => p.name).join(', ') }}
                                            <span v-if="chat.pending_invitations.length" class="text-amber-500"> · {{ chat.pending_invitations.map(i => i.display).join(', ') }} invited</span>
                                        </template>
                                    </p>
                                    <!-- Unread badge -->
                                    <span
                                        v-if="chat.unread_count > 0"
                                        class="flex h-5 min-w-[1.25rem] flex-shrink-0 items-center justify-center rounded-full bg-blue-500 px-1.5 text-[11px] font-bold text-white"
                                    >
                                        {{ chat.unread_count > 9 ? '9+' : chat.unread_count }}
                                    </span>
                                    <span v-else-if="chat.status === 'finalized'" class="flex-shrink-0 text-[11px] font-medium text-gray-400">Closed</span>
                                </div>
                            </div>
                        </Link>
                    </li>
                </ul>
            </div>

            <!-- ── Floating new chat button ──────────────────────────────── -->
            <div v-if="chats.length > 0" class="pointer-events-none absolute bottom-6 left-0 right-0 flex justify-center">
                <button
                    @click="showCreateModal = true"
                    class="pointer-events-auto inline-flex items-center gap-2 rounded-full bg-violet-600 px-6 py-3 text-sm font-semibold text-white shadow-2xl shadow-violet-300 transition hover:bg-violet-700 active:scale-95"
                >
                    <Plus class="h-4 w-4" />
                    Start new chat
                </button>
            </div>
        </div>

        <!-- ── Create Chat Modal ─────────────────────────────────────────── -->
        <Teleport to="body">
            <div
                v-if="showCreateModal"
                class="fixed inset-0 z-50 flex items-end justify-center bg-black/20 p-4 backdrop-blur-sm sm:items-center"
                @click.self="showCreateModal = false"
            >
                <div class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">

                    <div class="flex items-start justify-between">
                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-violet-50">
                            <Sparkles class="h-5 w-5 text-violet-600" />
                        </div>
                        <button
                            @click="showCreateModal = false"
                            class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <h2 class="mt-4 text-base font-semibold text-gray-900">New Mediation Session</h2>
                    <p class="mt-1 text-sm text-gray-400">
                        Search for participants by username. They'll see the invite on their dashboard.
                    </p>

                    <!-- Quick start templates -->
                    <div class="mt-4">
                        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-400">Quick start</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="t in templates"
                                :key="t.label"
                                type="button"
                                @click="applyTemplate(t)"
                                :class="[
                                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition',
                                    form.context_type === t.context_type && form.title === t.title
                                        ? 'border-violet-600 bg-violet-600 text-white'
                                        : 'border-gray-200 bg-white text-gray-600 hover:border-violet-200 hover:bg-violet-50'
                                ]"
                            >
                                {{ t.emoji }} {{ t.label }}
                            </button>
                        </div>
                    </div>

                    <form @submit.prevent="createChat" class="mt-5 space-y-4">

                        <!-- Context type -->
                        <div>
                            <label class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-gray-500">Context Type</label>
                            <select
                                v-model="form.context_type"
                                class="block w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 focus:border-violet-300 focus:bg-white focus:outline-none focus:ring-2 focus:ring-violet-100 transition"
                            >
                                <option v-for="ct in contextTypes" :key="ct.value" :value="ct.value">{{ ct.label }}</option>
                            </select>
                            <p v-if="form.errors.context_type" class="mt-1 text-xs text-red-500">{{ form.errors.context_type }}</p>
                        </div>

                        <!-- Title -->
                        <div>
                            <label class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-gray-500">
                                Session Title <span class="normal-case text-gray-300">(optional)</span>
                            </label>
                            <input
                                v-model="form.title"
                                type="text"
                                placeholder="e.g. Budget planning disagreement"
                                class="block w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 placeholder:text-gray-300 focus:border-violet-300 focus:bg-white focus:outline-none focus:ring-2 focus:ring-violet-100 transition"
                            />
                        </div>

                        <!-- Invite method toggle -->
                        <div>
                            <label class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-gray-500">How to invite</label>
                            <div class="flex overflow-hidden rounded-xl border border-gray-200 text-sm">
                                <button
                                    type="button"
                                    :class="['flex flex-1 items-center justify-center gap-1.5 py-2.5 font-medium transition', !useInviteLink ? 'bg-violet-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-50']"
                                    @click="useInviteLink = false"
                                >
                                    <UserPlus class="h-3.5 w-3.5" />
                                    By username
                                </button>
                                <button
                                    type="button"
                                    :class="['flex flex-1 items-center justify-center gap-1.5 py-2.5 font-medium transition', useInviteLink ? 'bg-violet-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-50']"
                                    @click="useInviteLink = true"
                                >
                                    <Link2 class="h-3.5 w-3.5" />
                                    Shareable link
                                </button>
                            </div>
                        </div>

                        <!-- Invite by username -->
                        <div v-if="!useInviteLink">
                            <!-- Selected chips -->
                            <div v-if="selectedUsers.length > 0" class="mb-2 flex flex-wrap gap-1.5">
                                <span
                                    v-for="u in selectedUsers"
                                    :key="u.id"
                                    class="inline-flex items-center gap-1 rounded-full bg-violet-600 py-1 pl-3 pr-1.5 text-xs font-medium text-white"
                                >
                                    @{{ u.username }}
                                    <button type="button" @click="removeUser(u.id)" class="ml-0.5 rounded-full p-0.5 hover:bg-white/20">
                                        <X class="h-2.5 w-2.5" />
                                    </button>
                                </span>
                            </div>

                            <!-- Search field -->
                            <div v-if="selectedUsers.length < 2" class="relative">
                                <Search class="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-gray-300" />
                                <input
                                    v-model="userSearch"
                                    type="text"
                                    placeholder="Search by username or name…"
                                    class="block w-full rounded-xl border border-gray-200 bg-gray-50 py-2.5 pl-8 pr-3 text-sm text-gray-900 placeholder:text-gray-300 focus:border-violet-300 focus:bg-white focus:outline-none focus:ring-2 focus:ring-violet-100 transition"
                                    autocomplete="off"
                                />
                                <ul
                                    v-if="userResults.length > 0"
                                    class="absolute z-10 mt-1 w-full overflow-hidden rounded-xl border border-gray-100 bg-white shadow-lg"
                                >
                                    <li
                                        v-for="u in userResults"
                                        :key="u.id"
                                        @click="selectUser(u)"
                                        class="flex cursor-pointer items-center gap-3 px-4 py-2.5 text-sm hover:bg-violet-50"
                                    >
                                        <div class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-violet-100 text-xs font-semibold text-violet-600">
                                            {{ u.name.charAt(0).toUpperCase() }}
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-900">{{ u.name }}</p>
                                            <p class="text-xs text-gray-400">@{{ u.username }}</p>
                                        </div>
                                        <UserPlus class="ml-auto h-3.5 w-3.5 text-gray-300" />
                                    </li>
                                </ul>
                                <p v-else-if="userSearch.trim() && !searchLoading" class="mt-1 text-xs text-gray-400">
                                    No users found matching "{{ userSearch }}"
                                </p>
                            </div>
                        </div>

                        <!-- Shareable link info -->
                        <div v-else class="rounded-xl border border-dashed border-violet-200 bg-violet-50 px-4 py-3.5 text-sm text-violet-600">
                            <p class="font-medium text-violet-700">A link will be generated after you create the session.</p>
                            <p class="mt-1 text-xs text-violet-500">Paste it in WhatsApp, iMessage, or anywhere — the other person joins by clicking it.</p>
                        </div>

                        <!-- Actions -->
                        <div class="flex flex-col gap-2 pt-1">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="w-full rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:opacity-50"
                            >
                                {{ form.processing ? 'Creating…' : 'Create Session' }}
                            </button>
                            <button
                                type="button"
                                @click="showCreateModal = false"
                                class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
                            >
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
