<script setup lang="ts">
import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import ChatSidebar from '@/components/ChatSidebar.vue';
import ToastContainer from '@/components/ToastContainer.vue';
import { useToast } from '@/composables/useToast';

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
        <div class="flex flex-1 flex-col overflow-hidden m-3 ml-3 rounded-3xl bg-white shadow-sm">
            <slot />
        </div>
    </div>
    <ToastContainer />
</template>
