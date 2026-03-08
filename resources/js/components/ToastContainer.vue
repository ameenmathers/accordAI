<script setup lang="ts">
import { useToast } from '@/composables/useToast';
import { CheckCircle2, AlertCircle, Info, X } from 'lucide-vue-next';

const { toasts, remove } = useToast();
</script>

<template>
    <Teleport to="body">
        <div class="pointer-events-none fixed bottom-4 right-4 z-[9999] flex flex-col gap-2 sm:bottom-6 sm:right-6">
            <TransitionGroup
                enter-active-class="transition duration-300 ease-out"
                enter-from-class="translate-x-4 opacity-0"
                enter-to-class="translate-x-0 opacity-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="translate-x-0 opacity-100"
                leave-to-class="translate-x-4 opacity-0"
            >
                <div
                    v-for="toast in toasts"
                    :key="toast.id"
                    :class="[
                        'pointer-events-auto flex items-center gap-3 rounded-2xl px-4 py-3 shadow-lg',
                        toast.type === 'success' ? 'bg-emerald-50 ring-1 ring-emerald-100' :
                        toast.type === 'error'   ? 'bg-red-50 ring-1 ring-red-100' :
                                                   'bg-white ring-1 ring-gray-100',
                    ]"
                >
                    <CheckCircle2 v-if="toast.type === 'success'" class="h-4 w-4 flex-shrink-0 text-emerald-500" />
                    <AlertCircle  v-else-if="toast.type === 'error'"   class="h-4 w-4 flex-shrink-0 text-red-500" />
                    <Info         v-else                                class="h-4 w-4 flex-shrink-0 text-gray-400" />
                    <p :class="['text-sm font-medium', toast.type === 'success' ? 'text-emerald-700' : toast.type === 'error' ? 'text-red-700' : 'text-gray-700']">
                        {{ toast.message }}
                    </p>
                    <button
                        @click="remove(toast.id)"
                        :class="['ml-1 flex-shrink-0 rounded-full p-0.5 transition', toast.type === 'success' ? 'text-emerald-400 hover:text-emerald-600' : toast.type === 'error' ? 'text-red-400 hover:text-red-600' : 'text-gray-400 hover:text-gray-600']"
                    >
                        <X class="h-3 w-3" />
                    </button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
