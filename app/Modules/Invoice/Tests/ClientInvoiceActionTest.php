<?php

use App\Models\User;
use App\Modules\Invoice\Actions\CreateClientInvoiceAction;
use App\Modules\Invoice\Actions\UploadClientInvoiceAction;
use App\Modules\Invoice\DTOs\CreateClientInvoiceDTO;
use App\Modules\Invoice\DTOs\UploadClientInvoiceDTO;
use App\Modules\Invoice\Services\ClientInvoiceService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;


test('CreateClientInvoiceAction: empty items throws validation error', function () {
    $tenant  = Tenant::factory()->create();
    $lead    = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $creator = User::factory()->create();

    $action = new CreateClientInvoiceAction(new ClientInvoiceService());

    expect(fn () => $action->execute(
        new CreateClientInvoiceDTO(
            leadId: $lead->id,
            items: [],
            dueDate: null,
            introMessage: null,
            notes: null,
        ),
        $tenant,
        $creator,
    ))->toThrow(ValidationException::class);
});

test('CreateClientInvoiceAction: lead from different tenant throws validation error', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $lead    = Lead::factory()->create(['tenant_id' => $tenantA->id]);
    $creator = User::factory()->create();

    $action = new CreateClientInvoiceAction(new ClientInvoiceService());

    expect(fn () => $action->execute(
        new CreateClientInvoiceDTO(
            leadId: $lead->id,
            items: [['description' => 'Test', 'quantity' => 1, 'unit_price' => 100000]],
            dueDate: null,
            introMessage: null,
            notes: null,
        ),
        $tenantB,
        $creator,
    ))->toThrow(ValidationException::class);
});

test('UploadClientInvoiceAction: non-PDF file throws validation error', function () {
    Storage::fake('local');

    $tenant  = Tenant::factory()->create();
    $lead    = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $creator = User::factory()->create();

    $action  = new UploadClientInvoiceAction(new ClientInvoiceService());
    $notaPdf = UploadedFile::fake()->create('invoice.jpg', 100, 'image/jpeg');

    expect(fn () => $action->execute(
        new UploadClientInvoiceDTO(
            leadId: $lead->id,
            file: $notaPdf,
            dueDate: null,
            introMessage: null,
        ),
        $tenant,
        $creator,
    ))->toThrow(ValidationException::class);
});

test('UploadClientInvoiceAction: valid PDF creates invoice with uploaded type', function () {
    Storage::fake('local');

    $tenant  = Tenant::factory()->create();
    $lead    = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $creator = User::factory()->create();

    $action  = new UploadClientInvoiceAction(new ClientInvoiceService());
    $pdf     = UploadedFile::fake()->create('invoice.pdf', 500, 'application/pdf');

    $invoice = $action->execute(
        new UploadClientInvoiceDTO(
            leadId: $lead->id,
            file: $pdf,
            dueDate: now()->addDays(14)->toDateString(),
            introMessage: 'Berikut invoice Anda.',
        ),
        $tenant,
        $creator,
    );

    expect($invoice->pdf_path)->not->toBeNull()
        ->and($invoice->intro_message)->toBe('Berikut invoice Anda.');
});
