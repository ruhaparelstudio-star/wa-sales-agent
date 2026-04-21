<?php

use App\Models\User;
use App\Modules\Auth\Enums\TenantUserRole;
use App\Modules\Auth\Models\TenantInvitation;
use App\Modules\Auth\Models\TenantUser;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Subscription\Models\SubscriptionPlan;
use App\Modules\Tenancy\Actions\CreateTenantAction;
use App\Modules\Tenancy\DTOs\CreateTenantDTO;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    $this->action = app(CreateTenantAction::class);
    $this->plan = SubscriptionPlan::factory()->create();
});

test('create tenant action creates tenant record', function () {
    $dto = new CreateTenantDTO(
        name: 'Studio Andara',
        slug: 'studio-andara',
        adminName: 'Budi',
        adminEmail: 'budi@andara.com',
        planId: $this->plan->id,
    );

    $tenant = $this->action->execute($dto);

    expect($tenant)->toBeInstanceOf(Tenant::class);
    expect($tenant->name)->toBe('Studio Andara');
    expect($tenant->slug)->toBe('studio-andara');
    expect($tenant->is_active)->toBeTrue();
});

test('create tenant action creates admin user', function () {
    $dto = new CreateTenantDTO(
        name: 'Studio Andara',
        slug: 'studio-andara',
        adminName: 'Budi',
        adminEmail: 'budi@andara.com',
        planId: $this->plan->id,
    );

    $this->action->execute($dto);

    $user = User::where('email', 'budi@andara.com')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Budi');
});

test('create tenant action assigns vendor_admin role to user', function () {
    $dto = new CreateTenantDTO(
        name: 'Studio Andara',
        slug: 'studio-andara',
        adminName: 'Budi',
        adminEmail: 'budi@andara.com',
        planId: $this->plan->id,
    );

    $tenant = $this->action->execute($dto);
    $user = User::where('email', 'budi@andara.com')->first();

    $tenantUser = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
    expect($tenantUser)->not->toBeNull();
    expect($tenantUser->role)->toBe(TenantUserRole::VendorAdmin);
});

test('create tenant action creates pending invitation', function () {
    $dto = new CreateTenantDTO(
        name: 'Studio Andara',
        slug: 'studio-andara',
        adminName: 'Budi',
        adminEmail: 'budi@andara.com',
        planId: $this->plan->id,
    );

    $tenant = $this->action->execute($dto);
    $user = User::where('email', 'budi@andara.com')->first();

    $invitation = TenantInvitation::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
    expect($invitation)->not->toBeNull();
    expect(strlen($invitation->token))->toBe(64);
    expect($invitation->expires_at->isFuture())->toBeTrue();
    expect($invitation->accepted_at)->toBeNull();
});

test('create tenant action dispatches invitation job', function () {
    $dto = new CreateTenantDTO(
        name: 'Studio Andara',
        slug: 'studio-andara',
        adminName: 'Budi',
        adminEmail: 'budi@andara.com',
        planId: $this->plan->id,
    );

    $this->action->execute($dto);

    Queue::assertPushed(\App\Modules\Tenancy\Jobs\SendTenantInvitationJob::class);
});

test('create tenant action assigns selected plan to tenant', function () {
    $dto = new CreateTenantDTO(
        name: 'Studio Andara',
        slug: 'studio-andara',
        adminName: 'Budi',
        adminEmail: 'budi@andara.com',
        planId: $this->plan->id,
        trialDays: 7,
    );

    $tenant = $this->action->execute($dto);

    $subscription = Subscription::query()
        ->where('tenant_id', $tenant->id)
        ->latest()
        ->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->plan_id)->toBe($this->plan->id);
});

test('create tenant action provisions pricelist directory for tenant', function () {
    Storage::fake('local');

    $dto = new CreateTenantDTO(
        name: 'Studio Andara',
        slug: 'studio-andara',
        adminName: 'Budi',
        adminEmail: 'budi@andara.com',
        planId: $this->plan->id,
    );

    $tenant = $this->action->execute($dto);

    Storage::disk('local')->assertExists("tenants/{$tenant->id}/pricelists");
});
