<script setup lang="ts">
import { watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ChatSidebar from '@/components/ChatSidebar.vue';
import ToastContainer from '@/components/ToastContainer.vue';
import { useToast } from '@/composables/useToast';
import { LayoutGrid, MessageSquare } from 'lucide-vue-next';

const page = usePage<{ flash?: { success?: string; error?: string; info?: string } }>();
const { success, error, info } = useToast();

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) success(flash.success);
        if (flash?.error)   error(flash.error);
        if (flash?.info)    info(flash.info);
    },
    { deep: true },
);
</script>

<template>
    <div class="flex h-screen overflow-hidden bg-[#EEF0FB]">
        <ChatSidebar />
        <div class="flex flex-1 flex-col overflow-hidden m-3 rounded-3xl bg-white shadow-sm pb-16 md:pb-0">
            <slot />
        </div>
    </div>

    <!-- ── Mobile bottom nav ──────────────────────────────────────────────── -->
    <nav class="fixed bottom-0 left-0 right-0 z-40 flex border-t border-gray-100 bg-white md:hidden">
        <Link
            href="/dashboard"
            :class="[
                'flex flex-1 flex-col items-center gap-1 py-3 text-[10px] font-semibold transition',
                page.url === '/dashboard' ? 'text-violet-600' : 'text-gray-400'
            ]"
        >
            <LayoutGrid class="h-5 w-5" />
            Dashboard
        </Link>
        <Link
            href="/chats"
            :class="[
                'flex flex-1 flex-col items-center gap-1 py-3 text-[10px] font-semibold transition',
                page.url.startsWith('/chats') ? 'text-violet-600' : 'text-gray-400'
            ]"
        >
            <MessageSquare class="h-5 w-5" />
            Messages
        </Link>
    </nav>

    <ToastContainer />
</template>
