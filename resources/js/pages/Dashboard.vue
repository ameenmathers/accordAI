<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppDashboardLayout from '@/layouts/AppDashboardLayout.vue';
import { MessageSquare, Sparkles, ArrowRight, ShieldCheck, Users, Brain, Bell, Check, X } from 'lucide-vue-next';

interface PendingInvitation {
    id: number;
    chat_title: string;
    context_type: string;
    invited_by: string;
}

const props = defineProps<{
    pendingInvitations: PendingInvitation[];
}>();

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

function accept(id: number) {
    useForm({}).post(`/invitations/${id}/accept`, { preserveScroll: true });
}

function decline(id: number) {
    useForm({}).post(`/invitations/${id}/decline`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Dashboard" />

    <AppDashboardLayout>
        <div class="flex h-full flex-1 flex-col overflow-y-auto bg-[#F8F7FF] p-6 lg:p-8">
            <div class="mx-auto w-full max-w-4xl space-y-6">

                <!-- ── Pending Invitations ──────────────────────────────── -->
                <div v-if="pendingInvitations.length > 0">
                    <div class="mb-3 flex items-center gap-2">
                        <Bell class="h-4 w-4 text-violet-500" />
                        <h2 class="text-sm font-semibold text-gray-900">Pending Invitations</h2>
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-violet-600 text-[10px] font-bold text-white">
                            {{ pendingInvitations.length }}
                        </span>
                    </div>
                    <ul class="space-y-2">
                        <li
                            v-for="inv in pendingInvitations"
                            :key="inv.id"
                            class="flex items-center gap-4 rounded-2xl border border-violet-100 bg-white px-4 py-3 shadow-sm"
                        >
                            <!-- Context icon -->
                            <div :class="['flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-2xl', contextStyle(inv.context_type).bg]">
                                <MessageSquare :class="['h-4 w-4', contextStyle(inv.context_type).text]" />
                            </div>

                            <!-- Info -->
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-gray-900">{{ inv.chat_title }}</p>
                                <p class="mt-0.5 text-xs text-gray-400">
                                    Invited by <strong class="text-gray-700">{{ inv.invited_by }}</strong>
                                    · <span :class="['capitalize font-medium', contextStyle(inv.context_type).text]">{{ inv.context_type }}</span>
                                </p>
                            </div>

                            <!-- Actions -->
                            <div class="flex flex-shrink-0 items-center gap-2">
                                <button
                                    @click="accept(inv.id)"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm shadow-violet-200 transition hover:bg-violet-700"
                                >
                                    <Check class="h-3 w-3" />
                                    Accept
                                </button>
                                <button
                                    @click="decline(inv.id)"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-500 transition hover:border-gray-300 hover:text-gray-700"
                                >
                                    <X class="h-3 w-3" />
                                    Decline
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- ── Main card ────────────────────────────────────────── -->
                <div class="overflow-hidden rounded-3xl shadow-sm ring-1 ring-gray-100 lg:flex">

                    <!-- Left: content panel -->
                    <div class="flex-1 bg-white p-8 text-[13px] leading-[20px] lg:p-10">
                        <div class="mb-1 flex items-center gap-2">
                            <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-violet-600 shadow-sm shadow-violet-200">
                                <Sparkles class="h-4 w-4 text-white" />
                            </div>
                            <span class="text-xs font-bold uppercase tracking-widest text-violet-500">AccordAI</span>
                        </div>

                        <h1 class="mb-1 mt-5 text-xl font-bold text-gray-900">Welcome back</h1>
                        <p class="mb-6 text-gray-500">
                            Start a mediation session, invite participants, and let AI guide the conversation.
                        </p>

                        <!-- How it works list -->
                        <ul class="mb-6 flex flex-col gap-1">
                            <li class="flex items-start gap-3 rounded-2xl bg-violet-50 px-4 py-3">
                                <span class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full bg-violet-600 text-[10px] font-bold text-white">1</span>
                                <span class="text-gray-600">
                                    <strong class="text-gray-900">Create a session</strong> — choose a context type and invite participants by username or link
                                </span>
                            </li>
                            <li class="flex items-start gap-3 rounded-2xl bg-gray-50 px-4 py-3">
                                <span class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full bg-gray-300 text-[10px] font-bold text-white">2</span>
                                <span class="text-gray-600">
                                    <strong class="text-gray-900">AI mediates</strong> — AccordAI responds with neutral, evidence-based guidance after every message
                                </span>
                            </li>
                        </ul>

                        <!-- CTAs -->
                        <div class="flex flex-wrap gap-3">
                            <Link
                                href="/chats"
                                class="inline-flex items-center gap-2 rounded-2xl bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-violet-200 transition hover:bg-violet-700"
                            >
                                <MessageSquare class="h-4 w-4" />
                                Open Messages
                            </Link>
                            <Link
                                href="/chats"
                                class="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 transition hover:border-violet-200 hover:bg-violet-50 hover:text-violet-700"
                            >
                                New Session
                                <ArrowRight class="h-4 w-4" />
                            </Link>
                        </div>
                    </div>

                    <!-- Right: feature cards panel -->
                    <div class="bg-[#F5F3FF] p-8 lg:w-80 lg:shrink-0">
                        <p class="mb-4 text-xs font-bold uppercase tracking-widest text-violet-400">Features</p>
                        <ul class="space-y-3">
                            <li class="flex items-start gap-3 rounded-2xl bg-white p-4 shadow-sm text-[13px]">
                                <div class="mt-0.5 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-xl bg-violet-600 shadow-sm shadow-violet-200">
                                    <Users class="h-4 w-4 text-white" />
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-900">Multi-party sessions</p>
                                    <p class="mt-0.5 text-gray-500">2–3 participants, invited by username or link</p>
                                </div>
                            </li>
                            <li class="flex items-start gap-3 rounded-2xl bg-white p-4 shadow-sm text-[13px]">
                                <div class="mt-0.5 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-xl bg-violet-600 shadow-sm shadow-violet-200">
                                    <ShieldCheck class="h-4 w-4 text-white" />
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-900">Neutral AI mediator</p>
                                    <p class="mt-0.5 text-gray-500">Evidence-based, addresses everyone by name</p>
                                </div>
                            </li>
                            <li class="flex items-start gap-3 rounded-2xl bg-white p-4 shadow-sm text-[13px]">
                                <div class="mt-0.5 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-xl bg-violet-600 shadow-sm shadow-violet-200">
                                    <Brain class="h-4 w-4 text-white" />
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-900">Memory extraction</p>
                                    <p class="mt-0.5 text-gray-500">Claude extracts behavioral insights after each session</p>
                                </div>
                            </li>
                        </ul>
                    </div>

                </div>
            </div>
        </div>
    </AppDashboardLayout>
</template>
