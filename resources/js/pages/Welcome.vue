<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard, login, register } from '@/routes';
import { Sparkles, ArrowRight } from 'lucide-vue-next';

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
    <Head title="AccordAI — AI-mediated conversations" />

    <div class="flex min-h-screen flex-col bg-[#FDFDFC] text-[#1b1b18]">

        <!-- ── Nav ───────────────────────────────────────────────────────── -->
        <header class="border-b border-[#e3e3e0] px-6 py-4 lg:px-12">
            <div class="mx-auto flex max-w-4xl items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#1b1b18]">
                        <Sparkles class="h-4 w-4 text-white" />
                    </div>
                    <span class="text-sm font-semibold">AccordAI</span>
                </div>

                <nav class="flex items-center gap-2">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="dashboard()"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-[#1b1b18] bg-[#1b1b18] px-4 py-1.5 text-sm font-medium text-white transition hover:bg-black"
                    >
                        Dashboard <ArrowRight class="h-3.5 w-3.5" />
                    </Link>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="rounded-lg px-4 py-1.5 text-sm font-medium text-[#706f6c] transition hover:text-[#1b1b18]"
                        >
                            Log in
                        </Link>
                        <Link
                            v-if="canRegister"
                            :href="register()"
                            class="rounded-lg border border-[#1b1b18] bg-[#1b1b18] px-4 py-1.5 text-sm font-medium text-white transition hover:bg-black"
                        >
                            Get started
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <!-- ── Hero ──────────────────────────────────────────────────────── -->
        <main class="flex flex-1 flex-col items-center justify-center px-6 py-24 text-center lg:px-12">
            <div class="mx-auto max-w-2xl">

                <p class="mb-5 inline-flex items-center gap-1.5 rounded-full border border-[#e3e3e0] bg-white px-3 py-1 text-xs font-medium text-[#706f6c]">
                    <Sparkles class="h-3 w-3 text-[#1b1b18]" />
                    Open source · Built with Laravel & Vue
                </p>

                <h1 class="mb-5 text-5xl font-bold leading-[1.1] tracking-tight text-[#1b1b18] lg:text-6xl">
                    A smarter way<br />to work through conflict
                </h1>

                <p class="mx-auto mb-3 max-w-xl text-lg text-[#706f6c]">
                    AccordAI is an AI-powered mediation platform. Invite the other party, start a session, and let the AI keep both sides heard and the conversation on track.
                </p>

                <p class="mx-auto mb-10 max-w-md text-sm text-[#a9a9a4]">
                    No therapist, no lawyer, no back-and-forth emails. Just a structured, evidence-based conversation — private, async-ready, and free to self-host.
                </p>

                <div class="flex flex-wrap items-center justify-center gap-3">
                    <Link
                        v-if="canRegister"
                        :href="register()"
                        class="inline-flex items-center gap-2 rounded-xl border border-black bg-[#1b1b18] px-6 py-3 text-sm font-semibold text-white transition hover:bg-black"
                    >
                        Start a session — it's free
                        <ArrowRight class="h-4 w-4" />
                    </Link>
                    <Link
                        :href="login()"
                        class="inline-flex items-center gap-2 rounded-xl border border-[#e3e3e0] px-6 py-3 text-sm font-semibold text-[#706f6c] transition hover:border-[#c3c3be] hover:text-[#1b1b18]"
                    >
                        Log in
                    </Link>
                </div>

            </div>
        </main>

        <!-- ── Footer ────────────────────────────────────────────────────── -->
        <footer class="border-t border-[#e3e3e0] px-6 py-5 text-center text-xs text-[#a9a9a4]">
            © {{ new Date().getFullYear() }} AccordAI · AI-mediated conversations
        </footer>

    </div>
</template>
