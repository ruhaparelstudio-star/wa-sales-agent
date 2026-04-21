<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Enums\BookingFieldType;
use App\Modules\Booking\Enums\FormType;
use App\Modules\Booking\Models\BookingField;
use App\Modules\Booking\Models\BookingFormTemplate;
use App\Modules\Booking\Models\LeadBookingData;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class BookingSchemaService
{
    public function getActiveSchema(Tenant $tenant, FormType $type): ?BookingFormTemplate
    {
        $cacheKey = $this->schemaCacheKey($tenant, $type);

        try {
            $cached = $this->cache()->get($cacheKey);
            if ($cached !== null) {
                $template = $this->coerceTemplate($cached);
                if ($template !== null) {
                    return $template;
                }

                $this->cache()->forget($cacheKey);
            }
        } catch (Throwable $e) {
            Log::warning('[BookingSchemaService] Failed to read cached schema; clearing cache entry.', [
                'tenant_id' => $tenant->id,
                'form_type' => $type->value,
                'error' => $e->getMessage(),
            ]);
            $this->cache()->forget($cacheKey);
        }

        $template = BookingFormTemplate::forTenant($tenant->id)
            ->active()
            ->ofType($type)
            ->with(['fields' => fn ($q) => $q->orderBy('sort_order')])
            ->first();

        if ($template === null) {
            return null;
        }

        try {
            $this->cache()->put($cacheKey, $template, 600);
        } catch (Throwable $e) {
            Log::warning('[BookingSchemaService] Failed to cache active schema.', [
                'tenant_id' => $tenant->id,
                'form_type' => $type->value,
                'error' => $e->getMessage(),
            ]);
        }

        return $template;
    }

    public function getFieldsForContext(Tenant $tenant, Lead $lead, FormType $type): array
    {
        $template = $this->getActiveSchema($tenant, $type);

        if ($template === null) {
            return [];
        }

        $filled = LeadBookingData::forLead($lead->id)
            ->ofType($type)
            ->pluck('field_key')
            ->all();

        return $template->fields
            ->filter(fn ($field) => ! in_array($field->field_key, $filled))
            ->values()
            ->toArray();
    }

    public function getOrCreateTemplate(Tenant $tenant, FormType $type): BookingFormTemplate
    {
        $defaultName = match ($type) {
            FormType::Inquiry => 'Inquiry Form',
            FormType::Booking => 'Booking Form',
        };

        return BookingFormTemplate::firstOrCreate(
            ['tenant_id' => $tenant->id, 'form_type' => $type->value],
            ['name' => $defaultName, 'is_active' => true],
        );
    }

    public function addField(BookingFormTemplate $template, string $label, BookingFieldType $type, bool $required): BookingField
    {
        $maxOrder = $template->fields()->max('sort_order') ?? 0;

        $field = $template->fields()->create([
            'tenant_id'   => $template->tenant_id,
            'label'       => $label,
            'field_key'   => \Illuminate\Support\Str::slug($label, '_'),
            'field_type'  => $type->value,
            'is_required' => $required,
            'sort_order'  => $maxOrder + 1,
        ]);

        $this->invalidateCache($template->tenant);

        return $field;
    }

    public function toggleRequired(BookingField $field): void
    {
        $field->update(['is_required' => ! $field->is_required]);
    }

    public function deleteField(BookingField $field): void
    {
        $tenantId = $field->template->tenant_id;
        $field->delete();
        $this->invalidateCache(Tenant::find($tenantId));
    }

    public function moveUp(BookingField $field): void
    {
        $prev = $field->template->fields()
            ->where('sort_order', '<', $field->sort_order)
            ->orderByDesc('sort_order')
            ->first();

        if ($prev) {
            [$field->sort_order, $prev->sort_order] = [$prev->sort_order, $field->sort_order];
            $field->save();
            $prev->save();
        }
    }

    public function moveDown(BookingField $field): void
    {
        $next = $field->template->fields()
            ->where('sort_order', '>', $field->sort_order)
            ->orderBy('sort_order')
            ->first();

        if ($next) {
            [$field->sort_order, $next->sort_order] = [$next->sort_order, $field->sort_order];
            $field->save();
            $next->save();
        }
    }

    public function invalidateCache(Tenant $tenant): void
    {
        Cache::forever($this->versionCacheKey($tenant), $this->cacheVersion($tenant) + 1);
    }

    private function cache(): Repository
    {
        return Cache::store();
    }

    private function schemaCacheKey(Tenant $tenant, FormType $type): string
    {
        return sprintf(
            'booking:schema:tenant:%d:%s:v%d',
            $tenant->id,
            $type->value,
            $this->cacheVersion($tenant),
        );
    }

    private function versionCacheKey(Tenant $tenant): string
    {
        return "booking:tenant:{$tenant->id}:version";
    }

    private function cacheVersion(Tenant $tenant): int
    {
        return (int) $this->cache()->get($this->versionCacheKey($tenant), 1);
    }

    private function coerceTemplate(mixed $cached): ?BookingFormTemplate
    {
        if (! $cached instanceof BookingFormTemplate) {
            return null;
        }

        $cached->loadMissing(['fields' => fn ($q) => $q->orderBy('sort_order')]);

        return $cached;
    }
}
