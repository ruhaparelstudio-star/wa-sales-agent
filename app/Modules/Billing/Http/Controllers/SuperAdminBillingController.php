<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Actions\ApproveBillingPaymentAction;
use App\Modules\Billing\DTOs\ApproveBillingPaymentDTO;
use App\Modules\Billing\Models\BillingInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SuperAdminBillingController extends Controller
{
    public function index()
    {
        $invoices = BillingInvoice::with(['tenant', 'subscription.plan'])
            ->latest()
            ->paginate(25);

        return view('superadmin.billing.index', compact('invoices'));
    }

    public function approve(Request $request, int $id, ApproveBillingPaymentAction $action): RedirectResponse
    {
        $action->execute(
            new ApproveBillingPaymentDTO($id),
            $request->user(),
        );

        return redirect()->route('superadmin.billing.index')->with('success', 'Invoice approved and subscription renewed.');
    }
}
