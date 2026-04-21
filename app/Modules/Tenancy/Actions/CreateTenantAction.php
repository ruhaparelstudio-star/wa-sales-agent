<?php

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Auth\Enums\TenantUserRole;
use App\Modules\Auth\Models\TenantInvitation;
use App\Modules\Auth\Models\TenantUser;
use App\Modules\Auth\Services\TenantMembershipService;
use App\Modules\Subscription\Models\SubscriptionPlan;
use App\Modules\Subscription\Services\SubscriptionService;
use App\Modules\Tenancy\DTOs\CreateTenantDTO;
use App\Modules\Tenancy\Jobs\SendTenantInvitationJob;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateTenantAction
{
    public function __construct(
        private readonly TenantMembershipService $membershipService,
        private readonly SubscriptionService $subscriptionService,
    ) {}

    public function execute(CreateTenantDTO $dto): Tenant
    {
        return DB::transaction(function () use ($dto) {
            $tenant = Tenant::create([
                'name' => $dto->name,
                'slug' => $dto->slug,
                'is_active' => true,
            ]);

            Storage::disk('local')->makeDirectory("tenants/{$tenant->id}/pricelists");

            $user = User::firstOrCreate(
                ['email' => $dto->adminEmail],
                [
                    'name' => $dto->adminName,
                    'password' => bcrypt(Str::random(24)),
                ],
            );

            $this->membershipService->addUserToTenant($tenant, $user, TenantUserRole::VendorAdmin);

            $invitation = TenantInvitation::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'token' => Str::random(64),
                'expires_at' => now()->addHours(48),
            ]);

            $plan = SubscriptionPlan::query()->findOrFail($dto->planId);
            $this->subscriptionService->assignPlan($tenant, $plan, $dto->trialDays);

            SendTenantInvitationJob::dispatch($invitation)->afterCommit();

            return $tenant;
        });
    }
}
