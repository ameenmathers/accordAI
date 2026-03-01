<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Sparkles } from 'lucide-vue-next';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex min-h-svh flex-col items-center justify-center bg-[#EBEBEB] p-4">
        <div class="w-full max-w-sm">
            <!-- Card -->
            <div class="rounded-3xl bg-white px-8 py-10 shadow-xl">
                <!-- Logo -->
                <div class="mb-8 flex flex-col items-center gap-3">
                    <Link :href="home()">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-900 shadow-md">
                            <Sparkles class="h-6 w-6 text-white" />
                        </div>
                    </Link>
                    <div class="space-y-1 text-center">
                        <h1 class="text-xl font-semibold text-gray-900">{{ title }}</h1>
                        <p class="text-sm text-gray-400">{{ description }}</p>
                    </div>
                </div>

                <slot />
            </div>

            <!-- Footer -->
            <p class="mt-6 text-center text-xs text-gray-400">
                AccordAI · AI-mediated conversations
            </p>
        </div>
    </div>
</template>
