<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { MessageSquare, Sparkles, ArrowRight, ShieldCheck, Users, Brain, Bell, Check, X } from 'lucide-vue-next';
import { dashboard } from '@/routes';

interface PendingInvitation {
    id: number;
    chat_title: string;
    context_type: string;
    invited_by: string;
}

const props = defineProps<{
    pendingInvitations: PendingInvitation[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard().url },
];

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

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col overflow-y-auto bg-[#FDFDFC] p-6 lg:p-8">
            <div class="mx-auto w-full max-w-4xl space-y-6">

                <!-- ── Pending Invitations ──────────────────────────────── -->
                <div v-if="pendingInvitations.length > 0">
                    <div class="mb-3 flex items-center gap-2">
                        <Bell class="h-4 w-4 text-[#706f6c]" />
                        <h2 class="text-sm font-semibold text-[#1b1b18]">Pending Invitations</h2>
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-[#1b1b18] text-[10px] font-bold text-white">
                            {{ pendingInvitations.length }}
                        </span>
                    </div>
                    <ul class="space-y-2">
                        <li
                            v-for="inv in pendingInvitations"
                            :key="inv.id"
                            class="flex items-center gap-4 rounded-xl border border-[#e3e3e0] bg-white px-4 py-3 shadow-sm"
                        >
                            <!-- Context dot -->
                            <div :class="['flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl', contextStyle(inv.context_type).bg]">
                                <MessageSquare :class="['h-4 w-4', contextStyle(inv.context_type).text]" />
                            </div>

                            <!-- Info -->
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-[#1b1b18]">{{ inv.chat_title }}</p>
                                <p class="mt-0.5 text-xs text-[#706f6c]">
                                    Invited by <strong class="text-[#1b1b18]">{{ inv.invited_by }}</strong>
                                    · <span :class="['capitalize', contextStyle(inv.context_type).text]">{{ inv.context_type }}</span>
                                </p>
                            </div>

                            <!-- Actions -->
                            <div class="flex flex-shrink-0 items-center gap-2">
                                <button
                                    @click="accept(inv.id)"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-[#1b1b18] px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-black"
                                >
                                    <Check class="h-3 w-3" />
                                    Accept
                                </button>
                                <button
                                    @click="decline(inv.id)"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-[#e3e3e0] px-3 py-1.5 text-xs font-medium text-[#706f6c] transition hover:border-gray-300 hover:text-[#1b1b18]"
                                >
                                    <X class="h-3 w-3" />
                                    Decline
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- ── Main card ────────────────────────────────────────── -->
                <div class="overflow-hidden rounded-xl shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] lg:flex">

                    <!-- Left: content panel -->
                    <div class="flex-1 bg-white p-8 text-[13px] leading-[20px] lg:p-12">
                        <div class="mb-1 flex items-center gap-2">
                            <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-[#1b1b18]">
                                <Sparkles class="h-3.5 w-3.5 text-white" />
                            </div>
                            <span class="text-xs font-semibold uppercase tracking-widest text-[#706f6c]">AccordAI</span>
                        </div>

                        <h1 class="mb-1 mt-5 text-lg font-semibold text-[#1b1b18]">Welcome back</h1>
                        <p class="mb-6 text-[#706f6c]">
                            Start a mediation session, invite participants, and let AI guide the conversation.
                        </p>

                        <!-- How it works list -->
                        <ul class="mb-6 flex flex-col">
                            <li class="relative flex items-start gap-4 py-2.5 before:absolute before:top-1/2 before:bottom-0 before:left-[0.4rem] before:border-l before:border-[#e3e3e0]">
                                <span class="relative bg-white py-1">
                                    <span class="flex h-3.5 w-3.5 items-center justify-center rounded-full border border-[#e3e3e0] bg-[#FDFDFC] shadow-[0px_0px_1px_0px_rgba(0,0,0,0.03),0px_1px_2px_0px_rgba(0,0,0,0.06)]">
                                        <span class="h-1.5 w-1.5 rounded-full bg-[#1b1b18]" />
                                    </span>
                                </span>
                                <span class="text-[#706f6c]">
                                    <strong class="text-[#1b1b18]">Create a session</strong> — choose a context type and invite participants by username
                                </span>
                            </li>
                            <li class="relative flex items-start gap-4 py-2.5 before:absolute before:top-0 before:bottom-1/2 before:left-[0.4rem] before:border-l before:border-[#e3e3e0]">
                                <span class="relative bg-white py-1">
                                    <span class="flex h-3.5 w-3.5 items-center justify-center rounded-full border border-[#e3e3e0] bg-[#FDFDFC] shadow-[0px_0px_1px_0px_rgba(0,0,0,0.03),0px_1px_2px_0px_rgba(0,0,0,0.06)]">
                                        <span class="h-1.5 w-1.5 rounded-full bg-[#dbdbd7]" />
                                    </span>
                                </span>
                                <span class="text-[#706f6c]">
                                    <strong class="text-[#1b1b18]">AI mediates</strong> — AccordAI responds with neutral, evidence-based guidance after every message
                                </span>
                            </li>
                        </ul>

                        <!-- CTAs -->
                        <ul class="flex flex-wrap gap-3 text-sm">
                            <li>
                                <Link
                                    href="/chats"
                                    class="inline-flex items-center gap-2 rounded-sm border border-black bg-[#1b1b18] px-5 py-1.5 text-sm leading-normal text-white transition hover:bg-black"
                                >
                                    <MessageSquare class="h-3.5 w-3.5" />
                                    Go to Sessions
                                </Link>
                            </li>
                            <li>
                                <Link
                                    href="/chats"
                                    class="inline-flex items-center gap-2 rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] transition hover:border-[#1915014a]"
                                >
                                    New Session
                                    <ArrowRight class="h-3.5 w-3.5" />
                                </Link>
                            </li>
                        </ul>
                    </div>

                    <!-- Right: feature cards panel -->
                    <div class="relative bg-[#f5f5f3] p-8 lg:w-80 lg:shrink-0">
                        <p class="mb-4 text-xs font-semibold uppercase tracking-widest text-[#706f6c]">Features</p>
                        <ul class="space-y-3">
                            <li class="flex items-start gap-3 rounded-lg bg-white p-4 shadow-[0px_0px_0px_1px_rgba(26,26,0,0.08)] text-[13px]">
                                <div class="mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg bg-[#1b1b18]">
                                    <Users class="h-3.5 w-3.5 text-white" />
                                </div>
                                <div>
                                    <p class="font-medium text-[#1b1b18]">Multi-party sessions</p>
                                    <p class="mt-0.5 text-[#706f6c]">2–3 participants, invited by username or link</p>
                                </div>
                            </li>
                            <li class="flex items-start gap-3 rounded-lg bg-white p-4 shadow-[0px_0px_0px_1px_rgba(26,26,0,0.08)] text-[13px]">
                                <div class="mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg bg-[#1b1b18]">
                                    <ShieldCheck class="h-3.5 w-3.5 text-white" />
                                </div>
                                <div>
                                    <p class="font-medium text-[#1b1b18]">Neutral AI mediator</p>
                                    <p class="mt-0.5 text-[#706f6c]">Evidence-based, addresses everyone by name</p>
                                </div>
                            </li>
                            <li class="flex items-start gap-3 rounded-lg bg-white p-4 shadow-[0px_0px_0px_1px_rgba(26,26,0,0.08)] text-[13px]">
                                <div class="mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg bg-[#1b1b18]">
                                    <Brain class="h-3.5 w-3.5 text-white" />
                                </div>
                                <div>
                                    <p class="font-medium text-[#1b1b18]">Memory extraction</p>
                                    <p class="mt-0.5 text-[#706f6c]">Claude extracts behavioral insights after each session</p>
                                </div>
                            </li>
                        </ul>
                    </div>

                </div>
            </div>
        </div>
    </AppLayout>
</template>
