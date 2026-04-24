<?php

namespace App\Modules\Leads\Services;

use App\Modules\AgentCore\Services\SlotExtractionService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadMemory;
use Illuminate\Support\Facades\Log;

class LeadMemoryService
{
    public function upsert(Lead $lead, array $extractedFields): void
    {
        $memory = LeadMemory::firstOrCreate(
            ['lead_id' => $lead->id],
            ['tenant_id' => $lead->tenant_id],
        );

        $jsonFields = ['preferred_packages', 'objections', 'custom_fields'];
        $updates = [];

        foreach ($extractedFields as $key => $value) {
            if ($value === null) {
                continue;
            }

            $normalizedValue = $this->normalizeFieldValue($key, $value);

            if ($normalizedValue === null) {
                continue;
            }

            if (in_array($key, $jsonFields, true)) {
                $existing = $memory->{$key} ?? [];
                $updates[$key] = match ($key) {
                    'preferred_packages' => array_values(array_unique(array_merge($existing, (array) $normalizedValue))),
                    'custom_fields' => array_merge($existing, (array) $normalizedValue),
                    default => array_merge($existing, (array) $normalizedValue),
                };
            } else {
                $updates[$key] = $normalizedValue;
            }
        }

        if (! empty($updates)) {
            $memory->update($updates);
        }
    }

    public function getSnapshot(Lead $lead): array
    {
        $memory = $lead->memory;
        $tenantServiceType = $lead->tenant?->primaryServiceSlug();

        if (! $memory) {
            return array_filter([
                'service_type' => $tenantServiceType,
            ], fn ($v) => $v !== null);
        }

        return array_filter([
            'name' => $memory->name,
            'event_date' => $memory->event_date?->toDateString(),
            'event_location' => SlotExtractionService::sanitizeLocationCandidate($memory->event_location),
            'budget_min' => $memory->budget_min,
            'budget_max' => $memory->budget_max,
            'service_type' => $memory->service_type ?? $tenantServiceType,
            'guest_count' => $memory->guest_count,
            'pricing_focus' => $memory->custom_fields['pricing_focus'] ?? null,
            'package_interest' => $memory->custom_fields['package_interest'] ?? (($memory->preferred_packages ?? [])[0] ?? null),
            'payment_topic' => $memory->custom_fields['payment_topic'] ?? null,
            'event_time_start' => $memory->custom_fields['event_time_start'] ?? null,
            'event_time_end' => $memory->custom_fields['event_time_end'] ?? null,
            'preferred_packages' => $memory->preferred_packages,
            'objections' => $memory->objections,
            'custom_fields' => $memory->custom_fields,
        ], fn ($v) => $v !== null);
    }

    private function normalizeFieldValue(string $key, mixed $value): mixed
    {
        if ($key === 'event_location') {
            $normalized = SlotExtractionService::sanitizeLocationCandidate($value);

            if ($normalized === null) {
                $this->logSkippedField($key, $value, 'invalid_location_value');
            }

            return $normalized;
        }

        if ($key !== 'event_date') {
            return $value;
        }

        if (! is_scalar($value)) {
            $this->logSkippedField($key, $value, 'non_scalar_date_value');

            return null;
        }

        $normalized = $this->normalizeEventDate((string) $value);

        if ($normalized === null) {
            $this->logSkippedField($key, $value, 'unparseable_date_value');
        }

        return $normalized;
    }

    private function normalizeEventDate(string $value): ?string
    {
        $trimmed = trim(mb_strtolower($value));

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            return $trimmed;
        }

        if (preg_match('/\b(\d{1,2})\s+(januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember)(?:\s+(\d{4}))?\b/u', $trimmed, $matches) === 1) {
            $months = [
                'januari' => 1,
                'februari' => 2,
                'maret' => 3,
                'april' => 4,
                'mei' => 5,
                'juni' => 6,
                'juli' => 7,
                'agustus' => 8,
                'september' => 9,
                'oktober' => 10,
                'november' => 11,
                'desember' => 12,
            ];

            $day = (int) $matches[1];
            $month = $months[$matches[2]] ?? null;
            $year = isset($matches[3]) ? (int) $matches[3] : (int) now()->format('Y');

            if ($month !== null && checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }

            return null;
        }

        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/u', $trimmed, $matches) === 1) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = isset($matches[3]) ? (int) $matches[3] : (int) now()->format('Y');

            if ($year < 100) {
                $year += 2000;
            }

            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return null;
    }

    private function logSkippedField(string $key, mixed $value, string $reason): void
    {
        Log::warning('[LeadMemoryService] Skipping invalid extracted field', [
            'field' => $key,
            'reason' => $reason,
            'value' => is_scalar($value) ? (string) $value : gettype($value),
        ]);
    }
}
