<?php

namespace App\Modules\Subscription\Http\Controllers;

use App\Modules\Subscription\Actions\AssignPlanAction;
use App\Modules\Subscription\DTOs\AssignPlanDTO;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SuperAdminSubscriptionController extends Controller
{
    public function assign(Request $request, AssignPlanAction $action): RedirectResponse
    {
        $request->validate([
            'tenant_id'  => ['required', 'integer', 'exists:tenants,id'],
            'plan_id'    => ['required', 'integer', 'exists:subscription_plans,id'],
            'trial_days' => ['nullable', 'integer', 'min:0'],
        ]);

        $action->execute(new AssignPlanDTO(
            tenantId:   $request->integer('tenant_id'),
            planId:     $request->integer('plan_id'),
            trialDays:  $request->integer('trial_days', 0),
        ));

        return redirect()->route('superadmin.tenants.index')->with('success', 'Plan assigned successfully.');
    }
}
