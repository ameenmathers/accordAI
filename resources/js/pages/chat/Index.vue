<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { MessageSquare, Plus, Users, Clock, CheckCircle, Hourglass, ChevronRight, X, Sparkles, Search, UserPlus, Link2 } from 'lucide-vue-next';

// ── Types ──────────────────────────────────────────────────────────────────
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
    messages_count: number;
    updated_at: string;
}
interface ContextType { value: string; label: string }
interface UserResult { id: number; name: string; username: string }

const props = defineProps<{ chats: Chat[]; contextTypes: ContextType[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Chats', href: '/chats' },
];

// ── Create chat modal ──────────────────────────────────────────────────────
const showCreateModal = ref(false);

const useInviteLink = ref(false);

const form = useForm({
    context_type: 'general',
    title: '',
    invitee_usernames: [] as string[],
    use_invite_link: false,
});

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
const contextColors: Record<string, { bg: string; text: string; dot: string }> = {
    relationship: { bg: 'bg-pink-50',    text: 'text-pink-600',    dot: 'bg-pink-400' },
    business:     { bg: 'bg-blue-50',    text: 'text-blue-600',    dot: 'bg-blue-400' },
    family:       { bg: 'bg-emerald-50', text: 'text-emerald-600', dot: 'bg-emerald-400' },
    financial:    { bg: 'bg-amber-50',   text: 'text-amber-600',   dot: 'bg-amber-400' },
    legal:        { bg: 'bg-violet-50',  text: 'text-violet-600',  dot: 'bg-violet-400' },
    general:      { bg: 'bg-gray-100',   text: 'text-gray-500',    dot: 'bg-gray-400' },
};

function contextStyle(type: string) {
    return contextColors[type] ?? contextColors.general;
}

function formatDate(dateStr: string) {
    return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function chatDisplayTitle(chat: Chat): string {
    return chat.title || `${chat.context_type.charAt(0).toUpperCase() + chat.context_type.slice(1)} Discussion`;
}
</script>

<template>
    <Head title="Chats" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-col bg-white">

            <!-- ── Page header ──────────────────────────────────────────── -->
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                <div>
                    <h1 class="text-lg font-semibold text-gray-900">Mediation Sessions</h1>
                    <p class="mt-0.5 text-sm text-gray-400">AI-guided conversations between 2–3 participants.</p>
                </div>
                <button
                    @click="showCreateModal = true"
                    class="inline-flex items-center gap-2 rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-gray-700"
                >
                    <Plus class="h-4 w-4" />
                    New Session
                </button>
            </div>

            <!-- ── Chat list ────────────────────────────────────────────── -->
            <div class="flex-1 overflow-y-auto">

                <!-- Empty state -->
                <div v-if="chats.length === 0" class="flex h-full flex-col items-center justify-center py-20 text-center">
                    <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-gray-100">
                        <MessageSquare class="h-7 w-7 text-gray-400" />
                    </div>
                    <h3 class="mt-4 text-base font-semibold text-gray-900">No sessions yet</h3>
                    <p class="mt-1.5 max-w-xs text-sm text-gray-400">
                        Start a mediation session and invite participants by username.
                    </p>
                    <button
                        @click="showCreateModal = true"
                        class="mt-6 inline-flex items-center gap-2 rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-gray-700"
                    >
                        <Plus class="h-4 w-4" />
                        Create first session
                    </button>
                </div>

                <!-- List -->
                <ul v-else class="divide-y divide-gray-50 px-4 py-3 sm:px-6">
                    <li v-for="chat in chats" :key="chat.id" class="group">
                        <Link
                            :href="`/chats/${chat.id}`"
                            class="flex items-center gap-4 rounded-2xl px-4 py-4 transition hover:bg-gray-50"
                        >
                            <div :class="['flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-2xl', contextStyle(chat.context_type).bg]">
                                <MessageSquare :class="['h-5 w-5', contextStyle(chat.context_type).text]" />
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="truncate text-sm font-semibold text-gray-900">
                                        {{ chatDisplayTitle(chat) }}
                                    </span>
                                    <span
                                        v-if="chat.status === 'waiting'"
                                        class="inline-flex flex-shrink-0 items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-600 ring-1 ring-amber-100"
                                    >
                                        <Hourglass class="h-2.5 w-2.5" />
                                        Waiting
                                    </span>
                                    <span
                                        v-else-if="chat.status === 'finalized'"
                                        class="inline-flex flex-shrink-0 items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500"
                                    >
                                        <CheckCircle class="h-2.5 w-2.5" />
                                        Closed
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex flex-shrink-0 items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-600"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400" />
                                        Active
                                    </span>
                                </div>

                                <div class="mt-0.5 flex items-center gap-1 text-xs text-gray-400">
                                    <Users class="h-3 w-3 flex-shrink-0" />
                                    <span class="truncate">
                                        {{ chat.participants.map(p => p.name).join(', ') }}
                                        <template v-if="chat.pending_invitations.length">
                                            · <span class="text-amber-500">{{ chat.pending_invitations.map(i => i.display).join(', ') }} invited</span>
                                        </template>
                                    </span>
                                </div>
                            </div>

                            <div class="flex flex-shrink-0 items-center gap-3 text-xs text-gray-400">
                                <span class="hidden sm:block">{{ chat.messages_count }} msgs</span>
                                <span class="inline-flex items-center gap-1">
                                    <Clock class="h-3 w-3" />
                                    {{ formatDate(chat.updated_at) }}
                                </span>
                                <ChevronRight class="h-4 w-4 text-gray-300 transition group-hover:text-gray-500" />
                            </div>
                        </Link>
                    </li>
                </ul>
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
                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gray-100">
                            <Sparkles class="h-5 w-5 text-gray-700" />
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

                    <form @submit.prevent="createChat" class="mt-5 space-y-4">

                        <!-- Context type -->
                        <div>
                            <label class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-gray-500">Context Type</label>
                            <select
                                v-model="form.context_type"
                                class="block w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:bg-white focus:outline-none transition"
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
                                class="block w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 placeholder:text-gray-300 focus:border-gray-400 focus:bg-white focus:outline-none transition"
                            />
                        </div>

                        <!-- Invite method toggle -->
                        <div>
                            <label class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-gray-500">How to invite</label>
                            <div class="flex overflow-hidden rounded-xl border border-gray-200 text-sm">
                                <button
                                    type="button"
                                    :class="['flex flex-1 items-center justify-center gap-1.5 py-2.5 font-medium transition', !useInviteLink ? 'bg-gray-900 text-white' : 'bg-white text-gray-500 hover:bg-gray-50']"
                                    @click="useInviteLink = false"
                                >
                                    <UserPlus class="h-3.5 w-3.5" />
                                    By username
                                </button>
                                <button
                                    type="button"
                                    :class="['flex flex-1 items-center justify-center gap-1.5 py-2.5 font-medium transition', useInviteLink ? 'bg-gray-900 text-white' : 'bg-white text-gray-500 hover:bg-gray-50']"
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
                                    class="inline-flex items-center gap-1 rounded-full bg-gray-900 py-1 pl-3 pr-1.5 text-xs font-medium text-white"
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
                                    class="block w-full rounded-xl border border-gray-200 bg-gray-50 py-2.5 pl-8 pr-3 text-sm text-gray-900 placeholder:text-gray-300 focus:border-gray-400 focus:bg-white focus:outline-none transition"
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
                                        class="flex cursor-pointer items-center gap-3 px-4 py-2.5 text-sm hover:bg-gray-50"
                                    >
                                        <div class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-gray-200 text-xs font-semibold text-gray-600">
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
                        <div v-else class="rounded-xl border border-dashed border-gray-200 bg-gray-50 px-4 py-3.5 text-sm text-gray-500">
                            <p class="font-medium text-gray-700">A link will be generated after you create the session.</p>
                            <p class="mt-1 text-xs">Paste it in WhatsApp, iMessage, or anywhere — the other person joins by clicking it.</p>
                        </div>

                        <!-- Actions -->
                        <div class="flex flex-col gap-2 pt-1">
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="w-full rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-gray-700 disabled:opacity-50"
                            >
                                {{ form.processing ? 'Creating...' : 'Create Session' }}
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
