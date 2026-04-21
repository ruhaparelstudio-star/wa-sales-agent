<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Enums\FormType;
use App\Modules\Booking\Models\BookingField;
use App\Modules\Booking\Models\BookingFormTemplate;
use App\Modules\Booking\Models\LeadBookingData;
use App\Modules\Leads\Models\Lead;
use Illuminate\Support\Facades\DB;

class LeadBookingDataService
{
    public function upsert(Lead $lead, FormType $type, array $fields): void
    {
        DB::transaction(function () use ($lead, $type, $fields) {
            foreach ($fields as $key => $value) {
                LeadBookingData::updateOrCreate(
                    [
                        'lead_id'   => $lead->id,
                        'form_type' => $type->value,
                        'field_key' => $key,
                    ],
                    [
                        'tenant_id'   => $lead->tenant_id,
                        'field_value' => $value,
                    ]
                );
            }
        });
    }

    public function getForLead(Lead $lead, FormType $type): array
    {
        return LeadBookingData::forLead($lead->id)
            ->ofType($type)
            ->pluck('field_value', 'field_key')
            ->all();
    }

    public function getMissingRequired(Lead $lead, FormType $type): array
    {
        $filled = $this->getForLead($lead, $type);

        $template = BookingFormTemplate::forTenant($lead->tenant_id)
            ->active()
            ->ofType($type)
            ->with('fields')
            ->first();

        if ($template === null) {
            return [];
        }

        return $template->fields
            ->filter(fn (BookingField $field) => $field->is_required && empty($filled[$field->field_key]))
            ->map(fn (BookingField $field) => $field->field_key)
            ->values()
            ->all();
    }

    public function nextMissingRequiredField(Lead $lead, FormType $type): ?BookingField
    {
        $filled = $this->getForLead($lead, $type);

        $template = BookingFormTemplate::forTenant($lead->tenant_id)
            ->active()
            ->ofType($type)
            ->with(['fields' => fn ($query) => $query->orderBy('sort_order')])
            ->first();

        if ($template === null) {
            return null;
        }

        return $template->fields
            ->first(fn (BookingField $field) => $field->is_required && empty($filled[$field->field_key]));
    }
}
