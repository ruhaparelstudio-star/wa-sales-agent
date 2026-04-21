<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Actions\UploadBillingProofAction;
use App\Modules\Billing\DTOs\UploadBillingProofDTO;
use App\Modules\Billing\Services\BillingInvoiceService;
use App\Modules\Subscription\Services\PlanService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingInvoiceService $billingInvoiceService,
        private readonly PlanService $planService,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index()
    {
        $tenant   = $this->tenantContext->getTenant();
        $invoices = $this->billingInvoiceService->getUnpaidInvoices($tenant);
        $plans    = $this->planService->getActivePlans();

        return view('billing.index', compact('invoices', 'plans'));
    }

    public function uploadProof(Request $request, int $id, UploadBillingProofAction $action): RedirectResponse
    {
        $request->validate([
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $tenant = $this->tenantContext->getTenant();

        $action->execute(
            new UploadBillingProofDTO($id, $request->file('proof')),
            $tenant->id,
        );

        return redirect()->route('billing.index')->with('success', 'Payment proof uploaded successfully.');
    }
}
