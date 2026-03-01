<?php

namespace App\Mail;

use App\Models\ChatInvitation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ChatInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ChatInvitation $invitation,
        public readonly User $invitedBy,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->invitedBy->name} invited you to an AccordAI mediation session",
        );
    }

    public function content(): Content
    {
        // Link goes to the PUBLIC landing page — user chooses login or register there
        $acceptUrl = route('invitations.show', $this->invitation->token);
        $contextType = ucfirst($this->invitation->chat->context_type);
        $chatTitle = $this->invitation->chat->title ?? "{$contextType} Discussion";

        return new Content(
            view: 'emails.chat-invitation',
            with: [
                'invitedBy' => $this->invitedBy,
                'chatTitle' => $chatTitle,
                'contextType' => $contextType,
                'acceptUrl' => $acceptUrl,
            ],
        );
    }
}
