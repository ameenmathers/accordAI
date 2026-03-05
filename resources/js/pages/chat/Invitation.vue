<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { MessageSquare, LogIn, UserPlus, ShieldCheck, Sparkles } from 'lucide-vue-next';

const props = defineProps<{
    token: string;
    invited_by: string;
    chat_title: string;
    context_type: string;
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
</script>

<template>
    <Head title="You've been invited — AccordAI" />

    <div class="flex min-h-screen flex-col items-center justify-center bg-neutral-100 px-4 py-16">
        <div class="w-full max-w-md">

            <!-- Logo -->
            <div class="mb-8 text-center">
                <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-900 shadow-lg">
                    <Sparkles class="h-6 w-6 text-white" />
                </div>
                <span class="text-xl font-bold text-gray-900">AccordAI</span>
                <p class="mt-0.5 text-sm text-gray-400">AI-mediated conversations</p>
            </div>

            <!-- Invite card -->
            <div class="rounded-3xl bg-white p-6 shadow-xl">

                <!-- Context badge + heading -->
                <span :class="['inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium capitalize', contextStyle(context_type).bg, contextStyle(context_type).text]">
                    <span :class="['h-1.5 w-1.5 rounded-full', contextStyle(context_type).dot]" />
                    {{ context_type }} Mediation
                </span>

                <h1 class="mt-4 text-lg font-semibold text-gray-900">
                    {{ invited_by }} invited you to a session
                </h1>

                <p class="mt-2 text-sm text-gray-500">
                    <strong class="text-gray-800">{{ chat_title }}</strong> — an AI-mediated conversation where AccordAI guides discussion with neutral, evidence-based insights.
                </p>

                <p class="mt-3 rounded-xl bg-gray-50 px-3 py-2.5 text-xs text-gray-500">
                    Sign in or create a free account to join. No email verification required.
                </p>

                <!-- How it works -->
                <div class="mt-5 border-t border-gray-100 pt-5">
                    <p class="mb-3 text-xs font-medium uppercase tracking-wider text-gray-400">How it works</p>
                    <ul class="space-y-2.5 text-sm text-gray-500">
                        <li class="flex items-start gap-2.5">
                            <ShieldCheck class="mt-0.5 h-4 w-4 flex-shrink-0 text-gray-400" />
                            AccordAI mediates — neutral and evidence-based
                        </li>
                        <li class="flex items-start gap-2.5">
                            <ShieldCheck class="mt-0.5 h-4 w-4 flex-shrink-0 text-gray-400" />
                            All participants must join before messaging begins
                        </li>
                        <li class="flex items-start gap-2.5">
                            <ShieldCheck class="mt-0.5 h-4 w-4 flex-shrink-0 text-gray-400" />
                            Your context is private and scoped to this session
                        </li>
                    </ul>
                </div>

                <!-- Auth CTAs -->
                <div class="mt-5 flex flex-col gap-2 border-t border-gray-100 pt-5">
                    <a
                        :href="`/invitations/${token}/login-redirect`"
                        class="flex w-full items-center justify-center gap-2 rounded-xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-gray-700"
                    >
                        <LogIn class="h-4 w-4" />
                        I already have an account — Log in
                    </a>
                    <a
                        :href="`/invitations/${token}/register-redirect`"
                        class="flex w-full items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                    >
                        <UserPlus class="h-4 w-4" />
                        Create a free account to join
                    </a>
                    <p class="text-center text-xs text-gray-400">
                        Free to join — no credit card required
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
