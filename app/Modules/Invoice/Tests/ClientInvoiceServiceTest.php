<?php

use App\Models\User;
use App\Modules\Invoice\Enums\ClientInvoiceStatus;
use App\Modules\Invoice\Enums\ClientInvoiceType;
use App\Modules\Invoice\Models\ClientInvoice;
use App\Modules\Invoice\Services\ClientInvoiceService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;


function makeClientInvoiceService(): ClientInvoiceService
{
    return new ClientInvoiceService();
}

test('invoice number is unique per tenant per month', function () {
    $tenant  = Tenant::factory()->create();
    $lead    = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $creator = User::factory()->create();
    $service = makeClientInvoiceService();

    $items = [['description' => 'Paket A', 'quantity' => 1, 'unit_price' => 5000000]];

    $inv1 = $service->createFromItems($tenant, $lead, $creator, $items, []);
    $inv2 = $service->createFromItems($tenant, $lead, $creator, $items, []);

    expect($inv1->invoice_number)->not->toBe($inv2->invoice_number);
});

test('invoice is linked to correct tenant and lead', function () {
    $tenant  = Tenant::factory()->create();
    $lead    = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $creator = User::factory()->create();
    $service = makeClientInvoiceService();

    $items = [['description' => 'Dekorasi', 'quantity' => 2, 'unit_price' => 2500000]];
    $invoice = $service->createFromItems($tenant, $lead, $creator, $items, ['notes' => 'Test']);

    expect($invoice->tenant_id)->toBe($tenant->id)
        ->and($invoice->lead_id)->toBe($lead->id)
        ->and($invoice->status)->toBe(ClientInvoiceStatus::Draft)
        ->and($invoice->invoice_type)->toBe(ClientInvoiceType::Created)
        ->and((float) $invoice->amount)->toBe(5000000.0);
});

test('cross-tenant: admin cannot access invoice of another tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $leadA   = Lead::factory()->create(['tenant_id' => $tenantA->id]);
    $creator = User::factory()->create();
    $service = makeClientInvoiceService();

    $items   = [['description' => 'Paket', 'quantity' => 1, 'unit_price' => 1000000]];
    $invoice = $service->createFromItems($tenantA, $leadA, $creator, $items, []);

    expect(fn () => $service->getInvoiceForTenant($invoice->id, $tenantB))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('attachUploadedPdf creates invoice with type=uploaded and stores pdf', function () {
    Storage::fake('local');

    $tenant  = Tenant::factory()->create();
    $lead    = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $creator = User::factory()->create();
    $service = makeClientInvoiceService();

    $file    = UploadedFile::fake()->create('invoice.pdf', 500, 'application/pdf');
    $invoice = $service->attachUploadedPdf($tenant, $lead, $creator, $file, ['due_date' => now()->addDays(7)->toDateString()]);

    expect($invoice->invoice_type)->toBe(ClientInvoiceType::Uploaded)
        ->and($invoice->pdf_path)->toContain("tenants/{$tenant->id}/invoices/");

    Storage::assertExists($invoice->pdf_path);
});

test('markAsPaid sets status to paid with paid_at timestamp', function () {
    $tenant  = Tenant::factory()->create();
    $lead    = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $invoice = ClientInvoice::factory()->sent()->create([
        'tenant_id' => $tenant->id,
        'lead_id'   => $lead->id,
    ]);

    makeClientInvoiceService()->markAsPaid($invoice);

    expect($invoice->fresh()->status)->toBe(ClientInvoiceStatus::Paid)
        ->and($invoice->fresh()->paid_at)->not->toBeNull();
});
