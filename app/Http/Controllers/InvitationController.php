<?php

namespace App\Http\Controllers;

use App\Models\ChatInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    /**
     * Public landing page for shareable links.
     * No auth required. Shows who invited them and what the session is about.
     * GET /invitations/{token}
     */
    public function show(string $token): Response|RedirectResponse
    {
        $invitation = ChatInvitation::where('token', $token)
            ->whereNull('accepted_at')
            ->whereNull('declined_at')
            ->with(['chat:id,context_type,title,created_by', 'chat.creator:id,name'])
            ->first();

        if (! $invitation) {
            $message = 'This invitation link has already been used or is no longer valid.';

            return auth()->check()
                ? redirect()->route('chats.index')->with('error', $message)
                : redirect()->route('login')->with('status', $message);
        }

        // If user is already logged in → accept immediately (shareable links are open to any user)
        if (auth()->check()) {
            // For user-targeted invitations, verify they're the right person
            if ($invitation->invited_user_id && $invitation->invited_user_id !== auth()->id()) {
                return redirect()->route('chats.index')
                    ->with('error', 'This invitation was sent to a different user.');
            }

            return $this->processAcceptance($invitation);
        }

        return Inertia::render('chat/Invitation', [
            'token' => $token,
            'invited_by' => $invitation->chat->creator->name,
            'chat_title' => $invitation->chat->title ?? ucfirst($invitation->chat->context_type).' Discussion',
            'context_type' => $invitation->chat->context_type,
        ]);
    }

    /**
     * Stores the acceptance URL as Laravel's "intended" destination, then sends
     * the user to /login. After login, Laravel auto-redirects to the intended URL.
     * GET /invitations/{token}/login-redirect
     */
    public function redirectToLogin(string $token): RedirectResponse
    {
        session(['url.intended' => route('invitations.accept', $token)]);

        return redirect()->route('login');
    }

    /**
     * Stores the token in session and sends the user to /register.
     * GET /invitations/{token}/register-redirect
     */
    public function redirectToRegister(string $token): RedirectResponse
    {
        session(['pending_invitation_token' => $token]);

        return redirect()->route('register');
    }

    /**
     * Shareable link acceptance — requires auth.
     * GET /invitations/{token}/accept
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = ChatInvitation::where('token', $token)
            ->whereNull('accepted_at')
            ->whereNull('declined_at')
            ->with('chat')
            ->first();

        if (! $invitation) {
            return redirect()->route('chats.index')
                ->with('error', 'This invitation has already been used or is no longer valid.');
        }

        if ($invitation->invited_user_id && $invitation->invited_user_id !== $request->user()->id) {
            return redirect()->route('chats.index')
                ->with('error', 'This invitation was sent to a different user.');
        }

        return $this->processAcceptance($invitation);
    }

    /**
     * Dashboard accept — for username-targeted invitations.
     * POST /invitations/{invitation}/accept
     */
    public function acceptById(Request $request, ChatInvitation $invitation): RedirectResponse
    {
        if ($invitation->invited_user_id !== $request->user()->id) {
            abort(403);
        }

        if (! $invitation->isPending()) {
            return redirect()->route('dashboard')
                ->with('error', 'This invitation is no longer pending.');
        }

        $invitation->load('chat');

        return $this->processAcceptance($invitation);
    }

    /**
     * Dashboard decline — for username-targeted invitations.
     * POST /invitations/{invitation}/decline
     */
    public function declineById(Request $request, ChatInvitation $invitation): RedirectResponse
    {
        if ($invitation->invited_user_id !== $request->user()->id) {
            abort(403);
        }

        if (! $invitation->isPending()) {
            return redirect()->route('dashboard')
                ->with('error', 'This invitation is no longer pending.');
        }

        $invitation->update(['declined_at' => now()]);

        // If all invitations resolved (declined or accepted), check chat status
        $chat = $invitation->chat;
        if ($chat->pendingInvitations()->count() === 0 && $chat->isWaiting()) {
            // If at least 2 participants, activate; otherwise keep waiting
            if ($chat->participants()->count() >= 2) {
                $chat->update(['status' => 'active']);
            }
        }

        return redirect()->route('dashboard')
            ->with('success', 'Invitation declined.');
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

        if ($chat->pendingInvitations()->count() === 0 && $chat->isWaiting()) {
            $chat->update(['status' => 'active']);
        }

        return redirect()->route('chats.show', $chat->id)
            ->with('success', 'You have joined the mediation session.');
    }
}
