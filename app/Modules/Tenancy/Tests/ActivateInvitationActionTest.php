<?php

use App\Models\User;
use App\Modules\Auth\Models\TenantInvitation;
use App\Modules\Tenancy\Actions\ActivateInvitationAction;
use App\Modules\Tenancy\DTOs\ActivateInvitationDTO;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->action = app(ActivateInvitationAction::class);

    $this->tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
    $this->user = User::factory()->create(['password' => bcrypt(Str::random(24))]);
});

function makeInvitation(User $user, Tenant $tenant, array $attrs = []): TenantInvitation
{
    return TenantInvitation::create(array_merge([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'token' => Str::random(64),
        'expires_at' => now()->addHours(48),
        'accepted_at' => null,
    ], $attrs));
}

test('valid token activates account and sets password', function () {
    $invitation = makeInvitation($this->user, $this->tenant);
    $dto = new ActivateInvitationDTO(token: $invitation->token, password: 'NewPassword123!');

    $result = $this->action->execute($dto);

    expect($result->id)->toBe($this->user->id);
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
    expect($this->user->fresh()->email_verified_at)->not->toBeNull();
});

test('expired token throws authorization exception', function () {
    $invitation = makeInvitation($this->user, $this->tenant, [
        'expires_at' => now()->subHour(),
    ]);

    $dto = new ActivateInvitationDTO(token: $invitation->token, password: 'NewPassword123!');

    expect(fn () => $this->action->execute($dto))
        ->toThrow(AuthorizationException::class);
});

test('already accepted token throws authorization exception', function () {
    $invitation = makeInvitation($this->user, $this->tenant, [
        'accepted_at' => now()->subMinutes(10),
    ]);

    $dto = new ActivateInvitationDTO(token: $invitation->token, password: 'NewPassword123!');

    expect(fn () => $this->action->execute($dto))
        ->toThrow(AuthorizationException::class);
});

test('invalid token throws authorization exception', function () {
    $dto = new ActivateInvitationDTO(token: 'invalid-token-that-does-not-exist', password: 'NewPassword123!');

    expect(fn () => $this->action->execute($dto))
        ->toThrow(AuthorizationException::class);
});
