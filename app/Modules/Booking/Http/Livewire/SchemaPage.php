<?php

namespace App\Modules\Booking\Http\Livewire;

use App\Modules\Booking\Enums\BookingFieldType;
use App\Modules\Booking\Enums\FormType;
use App\Modules\Booking\Models\BookingField;
use App\Modules\Booking\Models\BookingFormTemplate;
use App\Modules\Booking\Services\BookingSchemaService;
use App\Modules\Tenancy\Services\TenantContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Booking Schema')]
class SchemaPage extends Component
{
    public string $activeTab = 'inquiry';

    // Inline add form
    public bool   $showAddForm  = false;
    public string $formLabel    = '';
    public string $formType     = '';
    public bool   $formRequired = false;

    public function openAddForm(): void
    {
        $this->reset(['formLabel', 'formType', 'formRequired']);
        $this->showAddForm = true;
    }

    public function addField(TenantContext $tenantContext, BookingSchemaService $service): void
    {
        $this->validate([
            'formLabel' => 'required|string|max:255',
            'formType'  => 'required|in:' . implode(',', array_column(BookingFieldType::cases(), 'value')),
        ]);

        $formType = $this->activeTab === 'inquiry' ? FormType::Inquiry : FormType::Booking;
        $template = $service->getOrCreateTemplate($tenantContext->get(), $formType);

        $service->addField($template, $this->formLabel, BookingFieldType::from($this->formType), $this->formRequired);

        $this->showAddForm = false;
        session()->flash('success', 'Field added.');
    }

    public function toggleRequired(int $fieldId, TenantContext $tenantContext, BookingSchemaService $service): void
    {
        $tenant = $tenantContext->get();
        $field  = BookingField::whereHas('template', fn ($q) => $q->where('tenant_id', $tenant->id))
            ->findOrFail($fieldId);
        $service->toggleRequired($field);
    }

    public function deleteField(int $fieldId, TenantContext $tenantContext, BookingSchemaService $service): void
    {
        $tenant = $tenantContext->get();
        $field  = BookingField::whereHas('template', fn ($q) => $q->where('tenant_id', $tenant->id))
            ->findOrFail($fieldId);
        $service->deleteField($field);
    }

    public function moveUp(int $fieldId, TenantContext $tenantContext, BookingSchemaService $service): void
    {
        $tenant = $tenantContext->get();
        $field  = BookingField::whereHas('template', fn ($q) => $q->where('tenant_id', $tenant->id))
            ->findOrFail($fieldId);
        $service->moveUp($field);
    }

    public function moveDown(int $fieldId, TenantContext $tenantContext, BookingSchemaService $service): void
    {
        $tenant = $tenantContext->get();
        $field  = BookingField::whereHas('template', fn ($q) => $q->where('tenant_id', $tenant->id))
            ->findOrFail($fieldId);
        $service->moveDown($field);
    }

    public function render(TenantContext $tenantContext, BookingSchemaService $service)
    {
        $tenant       = $tenantContext->get();
        $inquiryTmpl  = $service->getOrCreateTemplate($tenant, FormType::Inquiry);
        $bookingTmpl  = $service->getOrCreateTemplate($tenant, FormType::Booking);

        return view('livewire.booking.schema-page', [
            'inquiryFields' => $inquiryTmpl->fields()->orderBy('sort_order')->get(),
            'bookingFields' => $bookingTmpl->fields()->orderBy('sort_order')->get(),
            'fieldTypes'    => BookingFieldType::cases(),
        ]);
    }
}
