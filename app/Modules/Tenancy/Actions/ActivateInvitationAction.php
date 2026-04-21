<?php

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Auth\Models\TenantInvitation;
use App\Modules\Tenancy\DTOs\ActivateInvitationDTO;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ActivateInvitationAction
{
    public function execute(ActivateInvitationDTO $dto): User
    {
        $invitation = TenantInvitation::where('token', $dto->token)->first();

        if ($invitation === null) {
            throw new AuthorizationException('Invalid invitation token.');
        }

        if ($invitation->isAccepted()) {
            throw new AuthorizationException('This invitation has already been used.');
        }

        if ($invitation->isExpired()) {
            throw new AuthorizationException('This invitation has expired.');
        }

        return DB::transaction(function () use ($invitation, $dto) {
            $user = $invitation->user;
            $user->password = $dto->password;
            $user->email_verified_at = now();
            $user->save();

            $invitation->update(['accepted_at' => now()]);

            return $user;
        });
    }
}
