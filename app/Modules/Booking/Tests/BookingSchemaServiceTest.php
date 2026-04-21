<?php

use App\Modules\Booking\Enums\FormType;
use App\Modules\Booking\Models\BookingField;
use App\Modules\Booking\Models\BookingFormTemplate;
use App\Modules\Booking\Models\LeadBookingData;
use App\Modules\Booking\Services\BookingSchemaService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;


beforeEach(fn () => Cache::flush());

function makeSchemaService(): BookingSchemaService
{
    return new BookingSchemaService();
}

test('getActiveSchema return template aktif dengan fields terurut sort_order', function () {
    $tenant   = Tenant::factory()->create();
    $template = BookingFormTemplate::factory()->forTenant($tenant)->inquiry()->create();

    BookingField::factory()->forTemplate($template)->create(['field_key' => 'tanggal', 'sort_order' => 2]);
    BookingField::factory()->forTemplate($template)->create(['field_key' => 'nama', 'sort_order' => 1]);
    BookingField::factory()->forTemplate($template)->create(['field_key' => 'budget', 'sort_order' => 3]);

    $schema = makeSchemaService()->getActiveSchema($tenant, FormType::Inquiry);

    expect($schema)->not->toBeNull()
        ->and($schema->fields->pluck('field_key')->all())->toBe(['nama', 'tanggal', 'budget']);
});

test('getActiveSchema return null jika tidak ada template aktif', function () {
    $tenant = Tenant::factory()->create();
    BookingFormTemplate::factory()->forTenant($tenant)->inquiry()->inactive()->create();

    $schema = makeSchemaService()->getActiveSchema($tenant, FormType::Inquiry);

    expect($schema)->toBeNull();
});

test('getActiveSchema tidak return template tenant lain', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    BookingFormTemplate::factory()->forTenant($tenantB)->inquiry()->create();

    $schema = makeSchemaService()->getActiveSchema($tenantA, FormType::Inquiry);

    expect($schema)->toBeNull();
});

test('getFieldsForContext return fields yang belum diisi lead', function () {
    $tenant   = Tenant::factory()->create();
    $agent    = WhatsAppAgent::factory()->connected()->create(['tenant_id' => $tenant->id]);
    $lead     = Lead::factory()->withAgent($agent)->create();
    $template = BookingFormTemplate::factory()->forTenant($tenant)->inquiry()->create();

    $field1 = BookingField::factory()->forTemplate($template)->create(['field_key' => 'nama', 'sort_order' => 1]);
    $field2 = BookingField::factory()->forTemplate($template)->create(['field_key' => 'tanggal', 'sort_order' => 2]);
    BookingField::factory()->forTemplate($template)->create(['field_key' => 'budget', 'sort_order' => 3]);

    LeadBookingData::create([
        'tenant_id'   => $tenant->id,
        'lead_id'     => $lead->id,
        'form_type'   => FormType::Inquiry->value,
        'field_key'   => 'nama',
        'field_value' => 'Budi',
    ]);

    $fields = makeSchemaService()->getFieldsForContext($tenant, $lead, FormType::Inquiry);

    $keys = array_column($fields, 'field_key');
    expect($keys)->not->toContain('nama')
        ->and($keys)->toContain('tanggal')
        ->and($keys)->toContain('budget');
});

test('addField menyimpan tenant_id yang sama dengan template', function () {
    $tenant = Tenant::factory()->create();
    $template = BookingFormTemplate::factory()->forTenant($tenant)->booking()->create();

    $field = makeSchemaService()->addField(
        $template,
        'Nama Pria & Wanita',
        \App\Modules\Booking\Enums\BookingFieldType::Text,
        true,
    );

    expect($field->tenant_id)->toBe($tenant->id)
        ->and($field->template_id)->toBe($template->id)
        ->and($field->field_key)->toBe('nama_pria_wanita')
        ->and($field->is_required)->toBeTrue();
});

test('getActiveSchema clears invalid cached payload and reloads from database', function () {
    $tenant = Tenant::factory()->create();
    $template = BookingFormTemplate::factory()->forTenant($tenant)->booking()->create();
    BookingField::factory()->forTemplate($template)->create(['field_key' => 'nama', 'sort_order' => 1]);

    $service = makeSchemaService();
    Cache::put('booking:schema:tenant:' . $tenant->id . ':booking:v1', new \stdClass(), 600);

    $schema = $service->getActiveSchema($tenant, FormType::Booking);

    expect($schema)->not->toBeNull()
        ->and($schema?->id)->toBe($template->id)
        ->and($schema?->fields->pluck('field_key')->all())->toBe(['nama']);
});
