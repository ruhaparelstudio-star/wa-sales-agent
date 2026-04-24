<?php

namespace App\Modules\AgentCore\Handlers;

use App\Modules\AgentCore\DTOs\BusinessResponsePayload;
use App\Modules\AgentCore\DTOs\PricelistInquiryHandlerInput;
use App\Modules\AgentCore\Enums\FinalAction;
use App\Modules\Knowledge\Services\PricelistService;

class PricelistInquiryHandler
{
    public function __construct(
        private readonly PricelistService $pricelistService,
    ) {}

    public function buildPayload(PricelistInquiryHandlerInput $input): BusinessResponsePayload
    {
        $relativePath = $input->relativePath;
        $deliveryStatus = $input->deliveryStatus;

        if ($deliveryStatus === 'resolve') {
            $relativePath = $relativePath ?? $this->pricelistService->findLatestPdf($input->lead->tenant);
            $deliveryStatus = $relativePath !== null ? 'ready_to_send' : 'missing';
        }

        return new BusinessResponsePayload(
            payloadType: 'pricelist_info',
            action: FinalAction::ReplyWithPriceDetails,
            data: [
                'intent' => $input->intent,
                'delivery_status' => $deliveryStatus,
                'pricelist_available' => $relativePath !== null,
                'relative_path' => $relativePath,
                'document_filename' => $relativePath !== null
                    ? ($this->pricelistService->filename($relativePath) ?? 'pricelist.pdf')
                    : null,
                'document_caption' => 'Pricelist terbaru kami',
                'next_best_action' => $deliveryStatus === 'ready_to_send' ? 'share_pricelist' : 'handoff_to_human',
                'tool_result_summary' => match ($deliveryStatus) {
                    'ready_to_send' => 'pricelist_pdf_queued',
                    'dispatch_failed' => 'pricelist_dispatch_failed',
                    default => 'pricelist_missing',
                },
                'handoff_required' => $deliveryStatus !== 'ready_to_send',
                'handoff_reason' => match ($deliveryStatus) {
                    'dispatch_failed' => 'pricelist_dispatch_failed',
                    'missing' => 'pricelist_missing',
                    default => null,
                },
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
