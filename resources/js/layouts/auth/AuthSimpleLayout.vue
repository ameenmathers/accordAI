<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { MessageSquare } from 'lucide-vue-next';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex min-h-svh bg-[#EEF0FB]">

        <!-- Left decorative panel (hidden on mobile) -->
        <div class="hidden lg:flex lg:w-[45%] flex-col items-center justify-center bg-gradient-to-br from-violet-600 to-purple-700 p-12 text-white relative overflow-hidden">
            <!-- Background circles -->
            <div class="absolute -top-24 -left-24 h-96 w-96 rounded-full bg-white/5" />
            <div class="absolute -bottom-32 -right-16 h-80 w-80 rounded-full bg-white/5" />
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 h-[500px] w-[500px] rounded-full bg-white/[0.03]" />

            <!-- Content -->
            <div class="relative z-10 max-w-sm text-center">
                <div class="mb-8 flex justify-center">
                    <div class="flex h-16 w-16 items-center justify-center rounded-3xl bg-white/20 shadow-2xl backdrop-blur-sm ring-1 ring-white/30">
                        <MessageSquare class="h-8 w-8 text-white" />
                    </div>
                </div>
                <h2 class="text-3xl font-bold tracking-tight">AccordAI</h2>
                <p class="mt-4 text-lg leading-relaxed text-white/70">
                    Chat free with an AI companion that listens, guides, and keeps conversations productive.
                </p>
                <div class="mt-10 flex flex-col gap-3 text-left">
                    <div class="flex items-center gap-3 rounded-2xl bg-white/10 px-4 py-3 backdrop-blur-sm">
                        <span class="text-xl">🧠</span>
                        <div>
                            <p class="text-sm font-semibold">AI-powered mediation</p>
                            <p class="text-xs text-white/60">Smart guidance in every conversation</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 rounded-2xl bg-white/10 px-4 py-3 backdrop-blur-sm">
                        <span class="text-xl">🔒</span>
                        <div>
                            <p class="text-sm font-semibold">Completely private</p>
                            <p class="text-xs text-white/60">Your sessions stay between you and AI</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 rounded-2xl bg-white/10 px-4 py-3 backdrop-blur-sm">
                        <span class="text-xl">⚡</span>
                        <div>
                            <p class="text-sm font-semibold">Real-time & free</p>
                            <p class="text-xs text-white/60">Always available, always free</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: form panel -->
        <div class="flex flex-1 flex-col items-center justify-center p-6 lg:p-12">
            <div class="w-full max-w-sm">

                <!-- Mobile logo -->
                <div class="mb-8 flex flex-col items-center gap-3 lg:hidden">
                    <Link :href="home()">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-600 shadow-lg shadow-violet-200 transition hover:bg-violet-700">
                            <MessageSquare class="h-6 w-6 text-white" />
                        </div>
                    </Link>
                    <span class="text-lg font-bold tracking-tight text-gray-900">AccordAI</span>
                </div>

                <!-- Card -->
                <div class="rounded-3xl bg-white px-8 py-9 shadow-xl shadow-violet-100/50 ring-1 ring-gray-100">
                    <div class="mb-7">
                        <h1 class="text-xl font-bold tracking-tight text-gray-900">{{ title }}</h1>
                        <p class="mt-1.5 text-sm text-gray-400">{{ description }}</p>
                    </div>
                    <slot />
                </div>

                <p class="mt-5 text-center text-xs text-gray-400">
                    AccordAI · Chat free with your AI companion
                </p>
            </div>
        </div>
    </div>
</template>
