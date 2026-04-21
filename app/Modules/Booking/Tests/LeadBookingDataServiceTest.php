<?php

use App\Modules\Booking\Enums\FormType;
use App\Modules\Booking\Models\BookingField;
use App\Modules\Booking\Models\BookingFormTemplate;
use App\Modules\Booking\Models\LeadBookingData;
use App\Modules\Booking\Services\LeadBookingDataService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;


function makeBookingDataService(): LeadBookingDataService
{
    return new LeadBookingDataService();
}

function makeLeadForTenant(Tenant $tenant): Lead
{
    $agent = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    return Lead::factory()->withAgent($agent)->create();
}

test('upsert menyimpan field baru tanpa duplikat field_key', function () {
    $tenant = Tenant::factory()->create();
    $lead   = makeLeadForTenant($tenant);

    makeBookingDataService()->upsert($lead, FormType::Inquiry, [
        'nama'    => 'Budi',
        'tanggal' => '2026-12-01',
    ]);

    expect(LeadBookingData::where('lead_id', $lead->id)->count())->toBe(2);
});

test('upsert update nilai field_key yang sudah ada', function () {
    $tenant = Tenant::factory()->create();
    $lead   = makeLeadForTenant($tenant);

    makeBookingDataService()->upsert($lead, FormType::Inquiry, ['nama' => 'Budi']);
    makeBookingDataService()->upsert($lead, FormType::Inquiry, ['nama' => 'Siti']);

    expect(LeadBookingData::where('lead_id', $lead->id)->count())->toBe(1)
        ->and(LeadBookingData::where('lead_id', $lead->id)->value('field_value'))->toBe('Siti');
});

test('getForLead return key-value pairs untuk lead dan form_type', function () {
    $tenant = Tenant::factory()->create();
    $lead   = makeLeadForTenant($tenant);

    makeBookingDataService()->upsert($lead, FormType::Inquiry, [
        'nama'    => 'Budi',
        'tanggal' => '2026-12-01',
    ]);

    $data = makeBookingDataService()->getForLead($lead, FormType::Inquiry);

    expect($data)->toBe(['nama' => 'Budi', 'tanggal' => '2026-12-01']);
});

test('getForLead tidak return data form_type lain', function () {
    $tenant = Tenant::factory()->create();
    $lead   = makeLeadForTenant($tenant);

    makeBookingDataService()->upsert($lead, FormType::Inquiry, ['nama' => 'Budi']);
    makeBookingDataService()->upsert($lead, FormType::Booking, ['tanggal_akad' => '2027-01-01']);

    $inquiry = makeBookingDataService()->getForLead($lead, FormType::Inquiry);

    expect($inquiry)->toHaveKey('nama')
        ->and($inquiry)->not->toHaveKey('tanggal_akad');
});

test('getMissingRequired return field required yang kosong', function () {
    $tenant   = Tenant::factory()->create();
    $lead     = makeLeadForTenant($tenant);
    $template = BookingFormTemplate::factory()->forTenant($tenant)->inquiry()->create();

    BookingField::factory()->forTemplate($template)->required()->create(['field_key' => 'nama', 'sort_order' => 1]);
    BookingField::factory()->forTemplate($template)->required()->create(['field_key' => 'tanggal', 'sort_order' => 2]);
    BookingField::factory()->forTemplate($template)->create(['field_key' => 'catatan', 'sort_order' => 3]); // not required

    makeBookingDataService()->upsert($lead, FormType::Inquiry, ['nama' => 'Budi']);

    $missing = makeBookingDataService()->getMissingRequired($lead, FormType::Inquiry);

    expect($missing)->toBe(['tanggal'])
        ->and($missing)->not->toContain('nama')
        ->and($missing)->not->toContain('catatan');
});

test('getMissingRequired return empty jika tidak ada template aktif', function () {
    $tenant = Tenant::factory()->create();
    $lead   = makeLeadForTenant($tenant);

    $missing = makeBookingDataService()->getMissingRequired($lead, FormType::Inquiry);

    expect($missing)->toBe([]);
});
