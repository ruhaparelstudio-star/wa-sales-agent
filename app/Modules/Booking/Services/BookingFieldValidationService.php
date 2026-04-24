<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Enums\BookingFieldType;
use App\Modules\Booking\Models\BookingField;
use App\Modules\Booking\Models\BookingFormTemplate;

class BookingFieldValidationService
{
    /**
     * Validate a single field value. Returns a human-readable error message,
     * or null when the value is acceptable for the field type.
     */
    public function validateField(BookingField $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return $field->is_required ? "{$field->label} wajib diisi." : null;
        }

        if ($field->field_type === BookingFieldType::Select && ! empty($field->options)) {
            if (! in_array($value, $field->options, true)) {
                return "{$field->label} harus salah satu dari pilihan yang tersedia.";
            }

            return null;
        }

        if ($field->field_type === BookingFieldType::Date && ! strtotime((string) $value)) {
            return "{$field->label} harus berupa tanggal yang valid.";
        }

        if ($field->field_type === BookingFieldType::Number && ! is_numeric($value)) {
            return "{$field->label} harus berupa angka.";
        }

        return null;
    }

    public function validate(array $data, BookingFormTemplate $template): array
    {
        $errors = [];

        foreach ($template->fields as $field) {
            $value = $data[$field->field_key] ?? null;

            if ($field->is_required && ($value === null || $value === '')) {
                $errors[$field->field_key] = "{$field->label} wajib diisi.";
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            if ($field->field_type === BookingFieldType::Select && ! empty($field->options)) {
                if (! in_array($value, $field->options)) {
                    $errors[$field->field_key] = "{$field->label} harus salah satu dari pilihan yang tersedia.";
                }
                continue;
            }

            if ($field->field_type === BookingFieldType::Date && ! strtotime((string) $value)) {
                $errors[$field->field_key] = "{$field->label} harus berupa tanggal yang valid.";
                continue;
            }

            if ($field->field_type === BookingFieldType::Number && ! is_numeric($value)) {
                $errors[$field->field_key] = "{$field->label} harus berupa angka.";
            }
        }

        return $errors;
    }
}
