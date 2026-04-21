<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Subscription\Services\PlanService;
use App\Modules\Subscription\Services\SubscriptionService;
use App\Modules\Auth\Models\TenantInvitation;
use App\Modules\Tenancy\Actions\CreateTenantAction;
use App\Modules\Tenancy\DTOs\CreateTenantDTO;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class SuperAdminTenantController extends Controller
{
    public function __construct(
        private readonly CreateTenantAction $createAction,
        private readonly SubscriptionService $subscriptionService,
        private readonly PlanService $planService,
    ) {}

    public function index(): View
    {
        $tenants = Tenant::with(['tenantUsers'])->latest()->paginate(20);

        return view('superadmin.tenants.index', compact('tenants'));
    }

    public function create(): View
    {
        $plans = $this->planService->getActivePlans();

        return view('superadmin.tenants.create', compact('plans'));
    }

    public function show(int $id): View
    {
        $tenant       = Tenant::findOrFail($id);
        $subscription = $this->subscriptionService->getActiveSub($tenant);
        $plans        = $this->planService->getActivePlans();
        $invitation   = TenantInvitation::query()
            ->where('tenant_id', $tenant->id)
            ->pending()
            ->latest('id')
            ->first();

        return view('superadmin.tenants.show', compact('tenant', 'subscription', 'plans', 'invitation'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'unique:tenants,slug', 'regex:/^[a-z0-9\-]+$/'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'trial_days' => ['nullable', 'integer', 'min:0'],
        ]);

        $tenant = $this->createAction->execute(CreateTenantDTO::fromArray($validated));
        $invitation = TenantInvitation::query()
            ->where('tenant_id', $tenant->id)
            ->pending()
            ->latest('id')
            ->first();

        return redirect()->route('superadmin.tenants.index')
            ->with('success', "Tenant {$tenant->name} berhasil dibuat. Email aktivasi telah dijadwalkan.")
            ->with('activation_url', $invitation ? url('/auth/activate?token=' . $invitation->token) : null);
    }
}
