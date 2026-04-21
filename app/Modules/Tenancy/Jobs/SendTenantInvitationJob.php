<?php

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Auth\Models\TenantInvitation;
use App\Modules\Tenancy\Mail\TenantInvitationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendTenantInvitationJob implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly TenantInvitation $invitation,
    ) {
        $this->onQueue('medium');
    }

    public function handle(): void
    {
        $invitation = $this->invitation->load(['tenant', 'user']);

        Mail::to($invitation->user->email)
            ->send(new TenantInvitationMail($invitation));
    }
}
