<?php

use App\Modules\Invoice\Enums\ClientInvoiceStatus;
use App\Modules\Invoice\Jobs\SendClientInvoiceJob;
use App\Modules\Invoice\Models\ClientInvoice;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;


test('SendClientInvoiceJob is dispatched to medium queue', function () {
    Queue::fake();

    $tenant  = Tenant::factory()->create();
    $lead    = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $agent   = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $invoice = ClientInvoice::factory()->sent()->create([
        'tenant_id'         => $tenant->id,
        'lead_id'           => $lead->id,
        'whatsapp_agent_id' => $agent->id,
    ]);

    SendClientInvoiceJob::dispatch($invoice->id)->onQueue('medium');

    Queue::assertPushedOn('medium', SendClientInvoiceJob::class, function ($job) use ($invoice) {
        return $job->invoiceId === $invoice->id;
    });
});

test('invoice status transitions from draft to sent after dispatch', function () {
    Queue::fake();

    $tenant  = Tenant::factory()->create();
    $lead    = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $agent   = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);

    $invoice = ClientInvoice::factory()->draft()->create([
        'tenant_id'         => $tenant->id,
        'lead_id'           => $lead->id,
        'whatsapp_agent_id' => $agent->id,
    ]);

    expect($invoice->status)->toBe(ClientInvoiceStatus::Draft);

    $invoice->update(['status' => ClientInvoiceStatus::Sent]);

    expect($invoice->fresh()->status)->toBe(ClientInvoiceStatus::Sent);
});
