<?php

namespace App\Modules\Invoice\Http\Controllers;

use App\Modules\Invoice\Actions\CreateClientInvoiceAction;
use App\Modules\Invoice\Actions\SendClientInvoiceAction;
use App\Modules\Invoice\Actions\UploadClientInvoiceAction;
use App\Modules\Invoice\DTOs\CreateClientInvoiceDTO;
use App\Modules\Invoice\DTOs\UploadClientInvoiceDTO;
use App\Modules\Invoice\Services\ClientInvoiceService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ClientInvoiceController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ClientInvoiceService $invoiceService,
    ) {}

    public function index(Lead $lead): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $invoices = $this->invoiceService->getInvoicesForLead($tenant, $lead);

        return response()->json(['data' => $invoices]);
    }

    public function show(Lead $lead, int $id): JsonResponse
    {
        $tenant  = $this->tenantContext->getTenant();
        $invoice = $this->invoiceService->getInvoiceForTenant($id, $tenant);

        return response()->json(['data' => $invoice->load('items')]);
    }

    public function store(Request $request, Lead $lead, CreateClientInvoiceAction $action): JsonResponse
    {
        $request->validate([
            'items'              => ['required', 'array', 'min:1'],
            'items.*.description'=> ['required', 'string'],
            'items.*.quantity'   => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'due_date'           => ['nullable', 'date'],
            'intro_message'      => ['nullable', 'string'],
            'notes'              => ['nullable', 'string'],
        ]);

        $tenant  = $this->tenantContext->getTenant();
        $invoice = $action->execute(
            new CreateClientInvoiceDTO(
                leadId: $lead->id,
                items: $request->input('items'),
                dueDate: $request->input('due_date'),
                introMessage: $request->input('intro_message'),
                notes: $request->input('notes'),
            ),
            $tenant,
            $request->user(),
        );

        return response()->json(['data' => $invoice], 201);
    }

    public function upload(Request $request, Lead $lead, UploadClientInvoiceAction $action): JsonResponse
    {
        $request->validate([
            'file'         => ['required', 'file', 'mimes:pdf', 'max:5120'],
            'due_date'     => ['nullable', 'date'],
            'intro_message'=> ['nullable', 'string'],
        ]);

        $tenant  = $this->tenantContext->getTenant();
        $invoice = $action->execute(
            new UploadClientInvoiceDTO(
                leadId: $lead->id,
                file: $request->file('file'),
                dueDate: $request->input('due_date'),
                introMessage: $request->input('intro_message'),
            ),
            $tenant,
            $request->user(),
        );

        return response()->json(['data' => $invoice], 201);
    }

    public function send(Request $request, Lead $lead, int $id, SendClientInvoiceAction $action): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $action->execute($id, $tenant);

        return response()->json(['message' => 'Invoice queued for sending.']);
    }
}
