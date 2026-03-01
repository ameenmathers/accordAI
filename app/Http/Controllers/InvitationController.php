<?php

namespace App\Http\Controllers;

use App\Models\ChatInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Handles the full invitation acceptance flow for both existing and new users.
 *
 * Two different auth paths — both handled:
 *
 * ── Existing user (login) ─────────────────────────────────────────────────
 *  1. /invitations/{token}          → public landing page
 *  2. "Log In" button               → redirectToLogin() stores url.intended, sends to /login
 *  3. After login                   → Laravel redirects to url.intended automatically
 *  4. /invitations/{token}/accept   → accept() verifies email, adds to chat
 *
 * ── New user (register) ──────────────────────────────────────────────────
 *  1. /invitations/{token}          → public landing page
 *  2. "Create Account" button       → redirectToRegister() stores token in session, sends to /register
 *  3. After registration            → FortifyServiceProvider checks session('pending_invitation_token')
 *                                     and redirects to /invitations/{token}/accept
 *  4. /invitations/{token}/accept   → accept() verifies email, adds to chat
 */
class InvitationController extends Controller
{
    /**
     * Public landing page — shown before the user is asked to log in or register.
     * No auth required. Shows who invited them and what the session is about.
     *
     * GET /invitations/{token}
     */
    public function show(string $token): Response|RedirectResponse
    {
        $invitation = ChatInvitation::where('token', $token)
            ->whereNull('accepted_at')
            ->with(['chat:id,context_type,title,created_by', 'chat.creator:id,name'])
            ->first();

        // Already accepted or invalid token — send to dashboard or login
        if (! $invitation) {
            $message = 'This invitation link has already been used or is no longer valid.';

            return auth()->check()
                ? redirect()->route('chats.index')->with('error', $message)
                : redirect()->route('login')->with('status', $message);
        }

        // If the user is already logged in and their email matches → accept immediately
        if (auth()->check()) {
            if (strtolower(auth()->user()->email) === strtolower($invitation->invited_email)) {
                return $this->processAcceptance($invitation);
            }

            // Logged in but wrong account
            return redirect()->route('chats.index')
                ->with('error', "This invitation was sent to {$invitation->invited_email}. Please log in with that account.");
        }

        return Inertia::render('chat/Invitation', [
            'token' => $token,
            'invited_email' => $invitation->invited_email,
            'invited_by' => $invitation->chat->creator->name,
            'chat_title' => $invitation->chat->title ?? ucfirst($invitation->chat->context_type).' Discussion',
            'context_type' => $invitation->chat->context_type,
        ]);
    }

    /**
     * Stores the acceptance URL as Laravel's "intended" destination, then sends
     * the user to /login. After login, Laravel auto-redirects to the intended URL.
     *
     * GET /invitations/{token}/login-redirect
     */
    public function redirectToLogin(string $token): RedirectResponse
    {
        // Store the protected accept URL as the intended destination.
        // Laravel's auth middleware reads session('url.intended') after login.
        session(['url.intended' => route('invitations.accept', $token)]);

        return redirect()->route('login');
    }

    /**
     * Stores the token in session and sends the user to /register.
     * FortifyServiceProvider reads this session value after registration
     * and redirects to the acceptance URL instead of /dashboard.
     *
     * GET /invitations/{token}/register-redirect
     */
    public function redirectToRegister(string $token): RedirectResponse
    {
        // Fortify's RegisterResponse ignores url.intended, so we store the token
        // separately. FortifyServiceProvider checks for this key post-registration.
        session(['pending_invitation_token' => $token]);

        return redirect()->route('register');
    }

    /**
     * The protected acceptance endpoint — requires the user to be logged in.
     * Verifies email, adds participant, activates chat if all invitations resolved.
     *
     * GET /invitations/{token}/accept
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = ChatInvitation::where('token', $token)
            ->whereNull('accepted_at')
            ->with('chat')
            ->first();

        if (! $invitation) {
            return redirect()->route('chats.index')
                ->with('error', 'This invitation has already been used or is no longer valid.');
        }

        if (strtolower($request->user()->email) !== strtolower($invitation->invited_email)) {
            return redirect()->route('chats.index')
                ->with('error', "This invitation was sent to {$invitation->invited_email}. You are logged in as {$request->user()->email}.");
        }

        return $this->processAcceptance($invitation);
    }

    /**
     * Shared logic for adding the user to the chat and activating it if ready.
     */
    private function processAcceptance(ChatInvitation $invitation): RedirectResponse
    {
        $chat = $invitation->chat;

        if (! $chat->participants()->where('user_id', auth()->id())->exists()) {
            $chat->participants()->attach(auth()->id());
        }

        $invitation->update(['accepted_at' => now()]);

        // Activate the chat once all pending invitations are resolved
        if ($chat->pendingInvitations()->count() === 0 && $chat->isWaiting()) {
            $chat->update(['status' => 'active']);
        }

        return redirect()->route('chats.show', $chat->id)
            ->with('success', 'You have joined the mediation session. It is now active.');
    }
}
