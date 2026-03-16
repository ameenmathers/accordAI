<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard, login, register } from '@/routes';
import { MessageSquare, ArrowRight, Sparkles, Shield, Zap, MessageCircle } from 'lucide-vue-next';

withDefaults(
    defineProps<{ canRegister: boolean }>(),
    { canRegister: true },
);
</script>

<template>
    <Head title="AccordAI — Chat free with your AI companion" />

    <div class="flex min-h-screen flex-col bg-[#EEF0FB] text-gray-900">

        <!-- ── Nav ───────────────────────────────────────────────────────── -->
        <header class="px-6 py-4 lg:px-12">
            <div class="mx-auto flex max-w-5xl items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <img src="/logo.png" alt="AccordAI" class="h-9 w-9 object-contain" />
                    <span class="text-base font-bold tracking-tight">AccordAI</span>
                </div>

                <nav class="flex items-center gap-2">
                    <Link v-if="$page.props.auth.user" :href="dashboard()" class="inline-flex items-center gap-1.5 rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700">
                        Open App <ArrowRight class="h-3.5 w-3.5" />
                    </Link>
                    <template v-else>
                        <Link :href="login()" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-500 transition hover:text-gray-900">Log in</Link>
                        <Link v-if="canRegister" :href="register()" class="rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700">Get started free</Link>
                    </template>
                </nav>
            </div>
        </header>

        <!-- ── Hero ──────────────────────────────────────────────────────── -->
        <main class="flex flex-1 flex-col items-center justify-center px-6 py-16 text-center lg:px-12">
            <div class="mx-auto max-w-2xl">

                <p class="mb-7 inline-flex items-center gap-2 rounded-full border border-violet-200 bg-white px-4 py-2 text-xs font-semibold text-violet-600 shadow-sm">
                    <Sparkles class="h-3.5 w-3.5" />
                    Chat free · Your AI companion is always ready
                </p>

                <h1 class="mb-5 text-5xl font-bold leading-[1.1] tracking-tight lg:text-6xl">
                    Talk it through<br />
                    <span class="text-violet-600">with AI by your side</span>
                </h1>

                <p class="mx-auto mb-4 max-w-xl text-lg leading-relaxed text-gray-500">
                    AccordAI gives you an AI companion that listens, mediates, and keeps every conversation productive — whether you're resolving a dispute or working through something difficult together.
                </p>

                <p class="mx-auto mb-10 max-w-md text-sm text-gray-400">
                    Invite the other party, start a session, and let AI keep both sides heard. Private, structured, and always free.
                </p>

                <div class="flex flex-wrap items-center justify-center gap-3">
                    <Link v-if="canRegister" :href="register()" class="inline-flex items-center gap-2 rounded-2xl bg-violet-600 px-7 py-3.5 text-sm font-bold text-white shadow-xl shadow-violet-300 transition hover:bg-violet-700 active:scale-[0.98]">
                        Start chatting free <ArrowRight class="h-4 w-4" />
                    </Link>
                    <Link :href="login()" class="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-7 py-3.5 text-sm font-semibold text-gray-600 shadow-sm transition hover:border-gray-300 hover:text-gray-900">
                        Log in
                    </Link>
                </div>

                <!-- Feature pills -->
                <div class="mt-12 flex flex-wrap items-center justify-center gap-3">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white border border-gray-200 px-3.5 py-1.5 text-xs font-medium text-gray-600 shadow-sm">
                        <Shield class="h-3 w-3 text-emerald-500" /> Completely private
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white border border-gray-200 px-3.5 py-1.5 text-xs font-medium text-gray-600 shadow-sm">
                        <Zap class="h-3 w-3 text-amber-500" /> Real-time AI mediation
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white border border-gray-200 px-3.5 py-1.5 text-xs font-medium text-gray-600 shadow-sm">
                        <MessageCircle class="h-3 w-3 text-violet-500" /> Up to 3 participants
                    </span>
                </div>

            </div>
        </main>

        <!-- ── Footer ────────────────────────────────────────────────────── -->
        <footer class="px-6 py-5 text-center text-xs text-gray-400">
            © {{ new Date().getFullYear() }} AccordAI · Chat free with your AI companion
        </footer>

    </div>
</template>
