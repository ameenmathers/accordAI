<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard, login, register } from '@/routes';
import { MessageCircle, ArrowRight, Sparkles, Shield, Zap } from 'lucide-vue-next';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);
</script>

<template>
    <Head title="AccordAI — Chat free with your AI companion" />

    <div class="flex min-h-screen flex-col bg-[#FAFAF9] text-[#1b1b18]">

        <!-- ── Nav ───────────────────────────────────────────────────────── -->
        <header class="px-6 py-4 lg:px-12">
            <div class="mx-auto flex max-w-5xl items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-9 w-9 items-center justify-center rounded-2xl bg-violet-600 shadow-lg shadow-violet-200">
                        <MessageCircle class="h-5 w-5 text-white" />
                    </div>
                    <span class="text-base font-bold tracking-tight">AccordAI</span>
                </div>

                <nav class="flex items-center gap-2">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="dashboard()"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700"
                    >
                        Open App <ArrowRight class="h-3.5 w-3.5" />
                    </Link>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="rounded-xl px-4 py-2 text-sm font-medium text-[#706f6c] transition hover:text-[#1b1b18]"
                        >
                            Log in
                        </Link>
                        <Link
                            v-if="canRegister"
                            :href="register()"
                            class="rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700"
                        >
                            Get started free
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <!-- ── Hero ──────────────────────────────────────────────────────── -->
        <main class="flex flex-1 flex-col items-center justify-center px-6 py-20 text-center lg:px-12">
            <div class="mx-auto max-w-2xl">

                <p class="mb-7 inline-flex items-center gap-2 rounded-full border border-violet-100 bg-violet-50 px-4 py-2 text-xs font-semibold text-violet-600">
                    <Sparkles class="h-3.5 w-3.5" />
                    Chat free · Your AI companion is always ready
                </p>

                <h1 class="mb-5 text-5xl font-bold leading-[1.1] tracking-tight text-[#1b1b18] lg:text-6xl">
                    A smarter way<br />to work through conflict
                </h1>

                <p class="mx-auto mb-4 max-w-xl text-lg leading-relaxed text-[#706f6c]">
                    AccordAI pairs you with an AI companion that listens, mediates, and keeps conversations productive — whether you're resolving a dispute or working through something difficult together.
                </p>

                <p class="mx-auto mb-10 max-w-md text-sm text-[#a9a9a4]">
                    Invite the other party, start a session, and let AI keep both sides heard. Private, structured, and always free.
                </p>

                <div class="flex flex-wrap items-center justify-center gap-3">
                    <Link
                        v-if="canRegister"
                        :href="register()"
                        class="inline-flex items-center gap-2 rounded-2xl bg-violet-600 px-7 py-3.5 text-sm font-bold text-white shadow-xl shadow-violet-200 transition hover:bg-violet-700 active:scale-[0.98]"
                    >
                        Start chatting free
                        <ArrowRight class="h-4 w-4" />
                    </Link>
                    <Link
                        :href="login()"
                        class="inline-flex items-center gap-2 rounded-2xl border border-[#e3e3e0] bg-white px-7 py-3.5 text-sm font-semibold text-[#706f6c] transition hover:border-[#c3c3be] hover:text-[#1b1b18]"
                    >
                        Log in
                    </Link>
                </div>

                <!-- Feature pills -->
                <div class="mt-12 flex flex-wrap items-center justify-center gap-3">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white border border-[#e3e3e0] px-3.5 py-1.5 text-xs font-medium text-[#706f6c]">
                        <Shield class="h-3 w-3 text-emerald-500" />
                        Completely private
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white border border-[#e3e3e0] px-3.5 py-1.5 text-xs font-medium text-[#706f6c]">
                        <Zap class="h-3 w-3 text-amber-500" />
                        Real-time AI mediation
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white border border-[#e3e3e0] px-3.5 py-1.5 text-xs font-medium text-[#706f6c]">
                        <MessageCircle class="h-3 w-3 text-violet-500" />
                        Up to 3 participants
                    </span>
                </div>

            </div>
        </main>

        <!-- ── Footer ────────────────────────────────────────────────────── -->
        <footer class="px-6 py-5 text-center text-xs text-[#a9a9a4]">
            © {{ new Date().getFullYear() }} AccordAI · Chat free with your AI companion
        </footer>

    </div>
</template>
