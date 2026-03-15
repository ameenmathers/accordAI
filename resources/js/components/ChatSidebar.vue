<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { MessageSquare, LayoutGrid, Settings, LogOut } from 'lucide-vue-next';

const page = usePage<{ auth: { user: { id: number; name: string; email: string; username?: string } } }>();
const authUser = computed(() => page.props.auth.user);

function userInitials(name: string): string {
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
}

function handleLogout() {
    router.flushAll();
}
</script>

<template>
    <aside class="flex w-56 flex-shrink-0 flex-col bg-white m-3 mr-0 rounded-3xl shadow-sm overflow-hidden">

        <!-- Logo -->
        <div class="px-6 pt-7 pb-5">
            <Link href="/chats" class="flex items-center gap-2.5">
                <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-violet-600 shadow-md shadow-violet-200">
                    <MessageSquare class="h-4 w-4 text-white" />
                </div>
                <span class="text-base font-bold tracking-tight text-gray-900">AccordAI</span>
            </Link>
        </div>

        <!-- User profile -->
        <div class="px-5 pb-6">
            <div class="flex flex-col items-center text-center">
                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-violet-100 text-lg font-bold text-violet-600 ring-4 ring-violet-50">
                    {{ userInitials(authUser.name) }}
                </div>
                <p class="mt-3 text-sm font-bold text-gray-900">{{ authUser.name }}</p>
                <p class="mt-0.5 text-xs text-gray-400">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-400 mr-1 align-middle"></span>
                    {{ authUser.username ? '@' + authUser.username : authUser.email.split('@')[0] }}
                </p>
            </div>
        </div>

        <!-- Nav -->
        <nav class="flex-1 px-3 space-y-0.5">
            <Link
                href="/chats"
                :class="[
                    'flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-semibold transition',
                    $page.url.startsWith('/chats')
                        ? 'bg-violet-600 text-white shadow-sm shadow-violet-200'
                        : 'text-gray-600 hover:bg-gray-50'
                ]"
            >
                <MessageSquare class="h-4 w-4" />
                Messages
            </Link>
            <Link
                href="/dashboard"
                :class="[
                    'flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-semibold transition',
                    $page.url === '/dashboard'
                        ? 'bg-violet-600 text-white shadow-sm shadow-violet-200'
                        : 'text-gray-600 hover:bg-gray-50'
                ]"
            >
                <LayoutGrid class="h-4 w-4" />
                Dashboard
            </Link>
        </nav>

        <!-- Bottom links -->
        <div class="border-t border-gray-50 px-3 py-4 space-y-0.5">
            <Link
                href="/settings/profile"
                class="flex items-center gap-3 rounded-2xl px-4 py-2.5 text-sm text-gray-500 transition hover:bg-gray-50 hover:text-gray-700"
            >
                <Settings class="h-4 w-4" />
                Settings
            </Link>
            <Link
                href="/logout"
                method="post"
                as="button"
                @click="handleLogout"
                class="flex w-full items-center gap-3 rounded-2xl px-4 py-2.5 text-sm text-gray-500 transition hover:bg-red-50 hover:text-red-500"
            >
                <LogOut class="h-4 w-4" />
                Log out
            </Link>
        </div>
    </aside>
</template>
