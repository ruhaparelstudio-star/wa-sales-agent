<?php

namespace App\Modules\AgentCore\Handlers;

use App\Modules\AgentCore\DTOs\BookingFieldReplyHandlerInput;
use App\Modules\AgentCore\DTOs\BusinessResponsePayload;
use App\Modules\AgentCore\Enums\FinalAction;
use App\Modules\Booking\Services\BookingFieldValidationService;
use App\Modules\Booking\Services\LeadBookingDataService;

class BookingFieldReplyHandler
{
    public function __construct(
        private readonly LeadBookingDataService $leadBookingDataService,
        private readonly BookingFieldValidationService $validationService,
    ) {}

    public function buildPayload(BookingFieldReplyHandlerInput $input): ?BusinessResponsePayload
    {
        $field = $this->leadBookingDataService->nextMissingRequiredField($input->lead, $input->formType);
        if ($field === null) {
            return null;
        }

        $value = trim((string) $input->message->content);
        if ($value === '') {
            return null;
        }

        $validationError = $this->validationService->validateField($field, $value);
        if ($validationError !== null) {
            return new BusinessResponsePayload(
                payloadType: 'booking_field_clarification',
                action: FinalAction::AskForBookingField,
                data: [
                    'saved_field' => null,
                    'invalid_field' => [
                        'key' => $field->field_key,
                        'label' => $field->label,
                        'raw_value' => $value,
                        'error' => $validationError,
                    ],
                    'next_field' => [
                        'key' => $field->field_key,
                        'label' => $field->label,
                    ],
                    'is_complete' => false,
                    'next_best_action' => 'collect_' . $field->field_key,
                    'tool_result_summary' => 'booking_field_invalid:' . $field->field_key,
                ],
                responseRules: [
                    'must_answer_latest_question_first' => true,
                    'must_not_invent_price' => true,
                    'must_not_invent_availability' => true,
                    'must_not_promise_followup_without_action' => true,
                ],
            );
        }

        $this->leadBookingDataService->upsert($input->lead, $input->formType, [
            $field->field_key => $value,
        ]);

        $nextField = $this->leadBookingDataService->nextMissingRequiredField($input->lead->fresh(), $input->formType);

        return new BusinessResponsePayload(
            payloadType: 'booking_field_clarification',
            action: FinalAction::AskForBookingField,
            data: [
                'saved_field' => [
                    'key' => $field->field_key,
                    'label' => $field->label,
                    'value' => $value,
                ],
                'next_field' => $nextField !== null ? [
                    'key' => $nextField->field_key,
                    'label' => $nextField->label,
                ] : null,
                'is_complete' => $nextField === null,
                'next_best_action' => $nextField !== null ? 'collect_' . $nextField->field_key : 'handoff_to_human',
                'tool_result_summary' => 'booking_field_saved:' . $field->field_key,
            ],
            responseRules: [
                'must_answer_latest_question_first' => true,
                'must_not_invent_price' => true,
                'must_not_invent_availability' => true,
                'must_not_promise_followup_without_action' => true,
            ],
        );
    }
}
