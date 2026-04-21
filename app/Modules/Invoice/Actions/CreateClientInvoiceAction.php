<?php

namespace App\Modules\Invoice\Actions;

use App\Models\User;
use App\Modules\Invoice\DTOs\CreateClientInvoiceDTO;
use App\Modules\Invoice\Models\ClientInvoice;
use App\Modules\Invoice\Services\ClientInvoiceService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Validation\ValidationException;

class CreateClientInvoiceAction
{
    public function __construct(
        private readonly ClientInvoiceService $invoiceService,
    ) {}

    public function execute(CreateClientInvoiceDTO $dto, Tenant $tenant, User $creator): ClientInvoice
    {
        $lead = Lead::forTenant($tenant->id)->find($dto->leadId);

        if (! $lead) {
            throw ValidationException::withMessages([
                'lead_id' => 'Lead not found or does not belong to your tenant.',
            ]);
        }

        if (empty($dto->items)) {
            throw ValidationException::withMessages([
                'items' => 'Invoice must have at least one item.',
            ]);
        }

        return $this->invoiceService->createFromItems(
            tenant: $tenant,
            lead: $lead,
            creator: $creator,
            items: $dto->items,
            meta: [
                'due_date'      => $dto->dueDate,
                'intro_message' => $dto->introMessage,
                'notes'         => $dto->notes,
            ],
        );
    }
}
