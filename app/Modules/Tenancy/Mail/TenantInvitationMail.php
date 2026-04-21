<?php

namespace App\Modules\Tenancy\Mail;

use App\Modules\Auth\Models\TenantInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TenantInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TenantInvitation $invitation,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Selamat Datang di ' . $this->invitation->tenant->name . ' — Aktivasi Akun Anda',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tenant.invitation',
            with: [
                'tenantName' => $this->invitation->tenant->name,
                'adminName' => $this->invitation->user->name,
                'activationUrl' => url('/auth/activate?token=' . $this->invitation->token),
                'expiresAt' => $this->invitation->expires_at->format('d M Y, H:i'),
            ],
        );
    }
}
