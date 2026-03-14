<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { MessageCircle } from 'lucide-vue-next';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex min-h-svh flex-col items-center justify-center bg-gradient-to-br from-violet-50 via-white to-purple-50 p-4">
        <div class="w-full max-w-sm">
            <!-- Card -->
            <div class="rounded-3xl bg-white px-8 py-10 shadow-xl shadow-violet-100 ring-1 ring-violet-100/50">
                <!-- Logo -->
                <div class="mb-8 flex flex-col items-center gap-3">
                    <Link :href="home()">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-600 shadow-lg shadow-violet-200 transition hover:bg-violet-700">
                            <MessageCircle class="h-6 w-6 text-white" />
                        </div>
                    </Link>
                    <div class="space-y-1 text-center">
                        <h1 class="text-xl font-bold tracking-tight text-gray-900">{{ title }}</h1>
                        <p class="text-sm text-gray-400">{{ description }}</p>
                    </div>
                </div>

                <slot />
            </div>

            <!-- Footer -->
            <p class="mt-6 text-center text-xs text-gray-400">
                AccordAI · Chat free with your AI companion
            </p>
        </div>
    </div>
</template>
