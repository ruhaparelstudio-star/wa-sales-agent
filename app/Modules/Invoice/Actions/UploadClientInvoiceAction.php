<?php

namespace App\Modules\Invoice\Actions;

use App\Models\User;
use App\Modules\Invoice\DTOs\UploadClientInvoiceDTO;
use App\Modules\Invoice\Models\ClientInvoice;
use App\Modules\Invoice\Services\ClientInvoiceService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Validation\ValidationException;

class UploadClientInvoiceAction
{
    public function __construct(
        private readonly ClientInvoiceService $invoiceService,
    ) {}

    public function execute(UploadClientInvoiceDTO $dto, Tenant $tenant, User $creator): ClientInvoice
    {
        $lead = Lead::forTenant($tenant->id)->find($dto->leadId);

        if (! $lead) {
            throw ValidationException::withMessages([
                'lead_id' => 'Lead not found or does not belong to your tenant.',
            ]);
        }

        if ($dto->file->getMimeType() !== 'application/pdf') {
            throw ValidationException::withMessages([
                'file' => 'Uploaded file must be a PDF.',
            ]);
        }

        return $this->invoiceService->attachUploadedPdf(
            tenant: $tenant,
            lead: $lead,
            creator: $creator,
            file: $dto->file,
            meta: [
                'due_date'      => $dto->dueDate,
                'intro_message' => $dto->introMessage,
            ],
        );
    }
}
