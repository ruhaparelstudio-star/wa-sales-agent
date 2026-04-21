<?php

namespace App\Modules\Conversations\Actions;

use App\Modules\Conversations\Models\Conversation;

class RecordAskedFieldAction
{
    /**
     * Append a field name to the conversation's asked_fields list (idempotent).
     * Also sets next_expected_field.
     */
    public function execute(Conversation $conversation, string $field, ?string $nextExpected = null): Conversation
    {
        $field = trim($field);
        if ($field === '') {
            return $conversation;
        }

        $existing = $conversation->askedFields();
        if (! in_array($field, $existing, true)) {
            $existing[] = $field;
        }

        $conversation->forceFill([
            'asked_fields'        => $existing,
            'next_expected_field' => $nextExpected !== null && $nextExpected !== '' ? $nextExpected : $conversation->next_expected_field,
        ])->save();

        return $conversation;
    }

    /**
     * @param  list<string>  $fields
     */
    public function executeMany(Conversation $conversation, array $fields, ?string $nextExpected = null): Conversation
    {
        $existing = $conversation->askedFields();
        foreach ($fields as $field) {
            $field = trim((string) $field);
            if ($field === '') {
                continue;
            }
            if (! in_array($field, $existing, true)) {
                $existing[] = $field;
            }
        }

        $conversation->forceFill([
            'asked_fields'        => $existing,
            'next_expected_field' => $nextExpected !== null && $nextExpected !== '' ? $nextExpected : $conversation->next_expected_field,
        ])->save();

        return $conversation;
    }
}
