<?php

namespace App\Modules\AgentCore\Dispatch;

use App\Modules\AgentCore\DTOs\BookingFieldReplyHandlerInput;
use App\Modules\AgentCore\DTOs\FinalTurnDecision;
use App\Modules\AgentCore\DTOs\PackageDetailsHandlerInput;
use App\Modules\AgentCore\DTOs\PricelistInquiryHandlerInput;
use App\Modules\AgentCore\Enums\FinalAction;
use App\Modules\AgentCore\Handlers\BookingFieldReplyHandler;
use App\Modules\AgentCore\Handlers\PackageDetailsInquiryHandler;
use App\Modules\AgentCore\Handlers\PricelistInquiryHandler;
use App\Modules\AgentCore\Services\BusinessPayloadResponder;
use App\Modules\AgentCore\Services\ContextAwareFallbackBuilder;
use App\Modules\AgentCore\Services\DelayPolicyService;
use App\Modules\AgentCore\Services\HumanizerService;
use App\Modules\Booking\Enums\FormType;
use App\Modules\Booking\Services\BookingSchemaService;
use App\Modules\Conversations\Enums\HandoffReason;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\ConversationStateService;
use App\Modules\Conversations\Services\HandoffRequestService;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadService;
use App\Modules\Leads\Services\LeadStageService;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Services\OutboundDispatchService;
use Illuminate\Support\Facades\Log;

final class ActionDispatcher
{
    public function __construct(
        private readonly LeadService $leadService,
        private readonly LeadStageService $leadStageService,
        private readonly HandoffRequestService $handoffRequestService,
        private readonly OutboundDispatchService $outboundDispatchService,
        private readonly HumanizerService $humanizerService,
        private readonly DelayPolicyService $delayPolicyService,
        private readonly ConversationStateService $conversationStateService,
        private readonly BookingSchemaService $bookingSchemaService,
        private readonly BookingFieldReplyHandler $bookingFieldReplyHandler,
        private readonly PackageDetailsInquiryHandler $packageDetailsInquiryHandler,
        private readonly PricelistInquiryHandler $pricelistInquiryHandler,
        private readonly BusinessPayloadResponder $businessPayloadResponder,
        private readonly ContextAwareFallbackBuilder $contextAwareFallbackBuilder,
        private readonly \App\Modules\Knowledge\Services\PricelistService $pricelistService,
    ) {}

    public function dispatch(FinalTurnDecision $decision, TurnDispatchContext $context): ActionDispatchResult
    {
        $action = FinalAction::coerce($decision->finalDecision['action'] ?? null);

        return match ($action) {
            FinalAction::DoNotReply => ActionDispatchResult::stop(
                noReplyReason: (string) ($decision->finalDecision['fallback_reason'] ?? 'do_not_reply'),
            ),
            FinalAction::ReplyWithOptOut => $this->dispatchOptOut($context),
            FinalAction::RequestHumanHandoff => $this->dispatchHandoff($decision, $context),
            FinalAction::GuideToBooking => $this->dispatchGuideToBooking($decision, $context),
            FinalAction::AskForBookingField => $this->dispatchBookingFieldReply($context),
            FinalAction::ReplyWithPricelist => $this->dispatchPricelist($decision, $context),
            FinalAction::ReplyWithGroundedPackage => $this->dispatchGroundedPackage($decision, $context),
            FinalAction::ReplyWithFallback => $this->dispatchFallback($decision, $context),
            default => ActionDispatchResult::continueToResponse(),
        };
    }

    private function dispatchOptOut(TurnDispatchContext $context): ActionDispatchResult
    {
        $this->queueAck(
            $context->agent,
            $context->lead,
            $context->conversation,
            'Baik, kami tidak akan menghubungi kamu lagi. Terima kasih ya.',
            $context->message,
            0,
            'handoff_to_human',
            'automation_paused:opt_out',
        );
        $this->leadService->pauseAutomation($context->lead);
        $this->handoffRequestService->create($context->lead, $context->conversation, HandoffReason::Other, 'opt_out');

        $context->turnLogger->setResponse('opt_out', null);

        return ActionDispatchResult::stop(shouldRefreshSummary: true);
    }

    private function dispatchHandoff(FinalTurnDecision $decision, TurnDispatchContext $context): ActionDispatchResult
    {
        $negativeSentiment = (bool) ($decision->detectedSignals['negative_sentiment'] ?? false);
        $handoffReasonValue = (string) (
            $decision->finalDecision['handoff_reason']
            ?? $decision->detectedSignals['handoff_reason']
            ?? $decision->finalDecision['intent']
            ?? 'other'
        );
        $reason = $this->mapHandoffReason($handoffReasonValue);

        if ($negativeSentiment) {
            $this->queueAck(
                $context->agent,
                $context->lead,
                $context->conversation,
                'Terima kasih sudah menginformasikan. Admin kami akan segera menghubungi kamu secara langsung.',
                $context->message,
                0,
                'handoff_to_human',
                'handoff_created:negative_sentiment',
            );
            $this->leadService->pauseAutomation($context->lead);
            $this->handoffRequestService->create($context->lead, $context->conversation, HandoffReason::NegativeSentiment);
            $context->turnLogger->setResponse('negative_sentiment', null);

            return ActionDispatchResult::stop(shouldRefreshSummary: true);
        }

        $ack = $this->handoffAcknowledgment($reason);
        if ($ack !== '') {
            $this->queueAck(
                $context->agent,
                $context->lead,
                $context->conversation,
                $ack,
                $context->message,
                0,
                'handoff_to_human',
                'handoff_created:' . $reason->value,
            );
        }

        $this->handoffRequestService->create(
            $context->lead,
            $context->conversation,
            $reason,
            $handoffReasonValue !== '' ? $handoffReasonValue : null,
        );

        $context->turnLogger->setResponse('handoff', null)->setTool('handoff');

        return ActionDispatchResult::stop(shouldRefreshSummary: true);
    }

    private function dispatchGuideToBooking(FinalTurnDecision $decision, TurnDispatchContext $context): ActionDispatchResult
    {
        $this->leadStageService->advanceStage(
            $context->lead,
            $this->nextStageFromDecision($context->lead, (string) ($decision->finalDecision['intent'] ?? '')),
        );

        $tenant = $context->lead->tenant;
        $schemaFailureMarker = $this->bookingSchemaFailureMarker($context->message);
        $state = $context->conversation->state()->first();

        if (($state?->last_tool_result_summary ?? null) === $schemaFailureMarker) {
            Log::warning('[ActionDispatcher] Booking schema failure guard prevented duplicate ready_to_book handling.', [
                'conversation_id' => $context->conversation->id,
                'message_id' => $context->message->id,
            ]);

            return ActionDispatchResult::stop(shouldRefreshSummary: true);
        }

        try {
            $template = $this->bookingSchemaService->getActiveSchema($tenant, FormType::Booking);
        } catch (\Throwable $e) {
            Log::error('[ActionDispatcher] Booking schema load failed', [
                'tenant_id' => $context->lead->tenant_id,
                'lead_id' => $context->lead->id,
                'conversation_id' => $context->conversation->id,
                'message_id' => $context->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->conversationStateService->recordToolResult(
                $context->conversation,
                $context->lead,
                $schemaFailureMarker,
                'respond_to_user',
            );

            $this->queueAck(
                $context->agent,
                $context->lead,
                $context->conversation,
                'Maaf, flow booking lagi aku tahan dulu ya. Aku belum bisa buka form booking saat ini, tapi aku tetap bisa bantu jelaskan detail paket atau lanjutkan pertanyaanmu dulu.',
                $context->message,
                0,
                'respond_to_user',
                $schemaFailureMarker,
            );

            $context->turnLogger->setResponse('ready_to_book', null)->setTool('booking_flow');

            return ActionDispatchResult::stop(shouldRefreshSummary: true);
        }

        if (! $template || $template->fields->isEmpty()) {
            $this->queueAck(
                $context->agent,
                $context->lead,
                $context->conversation,
                'Siap, aku bantu lanjut ke proses booking ya. Kirim dulu nama lengkap dan tanggal acaranya, nanti aku bantu arahkan step berikutnya.',
                $context->message,
                0,
                'collect_booking_fields',
                'booking_form_requested',
            );

            $context->turnLogger->setResponse('ready_to_book', null)->setTool('booking_flow');

            return ActionDispatchResult::stop(shouldRefreshSummary: true);
        }

        $lines = ['Siap, kita lanjut ke booking ya. Boleh bantu lengkapi data berikut di chat ini:', ''];

        foreach ($template->fields as $index => $field) {
            $required = $field->is_required ? ' *' : '';
            $lines[] = ($index + 1) . '. ' . $field->label . $required;
        }

        $lines[] = '';
        $lines[] = '(* wajib diisi)';
        $lines[] = '';
        $lines[] = 'Balas dengan format yang paling nyaman ya, nanti aku bantu rapikan.';

        $this->queueAck(
            $context->agent,
            $context->lead,
            $context->conversation,
            implode("\n", $lines),
            $context->message,
            2,
            'collect_booking_fields',
            'booking_form_requested',
        );

        $context->turnLogger->setResponse('ready_to_book', null)->setTool('booking_flow');

        return ActionDispatchResult::stop(shouldRefreshSummary: true);
    }

    private function dispatchBookingFieldReply(TurnDispatchContext $context): ActionDispatchResult
    {
        $payload = $this->bookingFieldReplyHandler->buildPayload(
            new BookingFieldReplyHandlerInput(
                lead: $context->lead,
                conversation: $context->conversation,
                message: $context->message,
                formType: FormType::Booking,
            ),
        );

        if ($payload === null) {
            return ActionDispatchResult::continueToResponse();
        }

        $rendered = $this->businessPayloadResponder->render($payload);
        $replyText = trim((string) ($rendered->text ?? ''));

        if ($replyText === '') {
            return ActionDispatchResult::continueToResponse();
        }

        $this->queueAck(
            $context->agent,
            $context->lead,
            $context->conversation,
            $replyText,
            $context->message,
            0,
            $rendered->nextBestAction,
            $rendered->toolResultSummary,
        );

        Log::info('[ActionDispatcher] Stored booking field reply', [
            'lead_id' => $context->lead->id,
            'payload_type' => $payload->payloadType,
            'saved_field' => $payload->data['saved_field']['key'] ?? null,
            'invalid_field' => $payload->data['invalid_field']['key'] ?? null,
            'next_field' => $payload->data['next_field']['key'] ?? null,
        ]);

        $context->turnLogger->setResponse('booking_field', null);

        return ActionDispatchResult::stop(shouldRefreshSummary: true);
    }

    private function dispatchPricelist(FinalTurnDecision $decision, TurnDispatchContext $context): ActionDispatchResult
    {
        $payload = $this->pricelistInquiryHandler->buildPayload(
            new PricelistInquiryHandlerInput(
                lead: $context->lead,
                conversation: $context->conversation,
                message: $context->message,
                intent: (string) ($decision->finalDecision['intent'] ?? 'tanya_harga'),
            ),
        );

        $rendered = $this->businessPayloadResponder->render($payload);

        if ($rendered->deliveryMode === 'document_follow_up') {
            $relativePath = trim((string) ($payload->data['relative_path'] ?? ''));
            if ($relativePath === '') {
                return ActionDispatchResult::continueToResponse();
            }

            $this->outboundDispatchService->queueSendDocument(
                agent: $context->agent,
                to: $context->lead->preferredWhatsAppRecipient(),
                filePath: $this->pricelistService->absolutePath($relativePath),
                filename: (string) ($payload->data['document_filename'] ?? 'pricelist.pdf'),
                idempotencyKey: $this->buildOutboundIdempotencyKey(
                    $context->conversation,
                    $context->message,
                    'pricelist_pdf',
                    'pricelist_document',
                ),
                caption: $rendered->caption,
                followUpText: $rendered->followUpText,
                followUpDelaySeconds: $this->delayPolicyService->getDelay((string) $rendered->followUpText),
                queue: 'high',
            );

            $this->conversationStateService->recordOutboundMessage(
                $context->conversation,
                $context->lead,
                (string) $rendered->followUpText,
                $rendered->nextBestAction,
                $rendered->toolResultSummary,
            );

            $context->turnLogger->setResponse('pricelist', '[pricelist]')->setTool('pricelist_share');

            return ActionDispatchResult::stop(shouldRefreshSummary: true);
        }

        $replyText = trim((string) ($rendered->text ?? ''));
        if ($replyText === '') {
            return ActionDispatchResult::continueToResponse();
        }

        if ((bool) ($payload->data['handoff_required'] ?? false)) {
            $this->createPricelistMissingHandoff($context->lead, $context->conversation);
        }

        $this->queueAck(
            $context->agent,
            $context->lead,
            $context->conversation,
            $replyText,
            $context->message,
            0,
            $rendered->nextBestAction,
            $rendered->toolResultSummary,
        );

        $context->turnLogger->setResponse('pricelist', '[pricelist]')->setTool('pricelist_share');

        return ActionDispatchResult::stop(shouldRefreshSummary: true);
    }

    private function dispatchGroundedPackage(FinalTurnDecision $decision, TurnDispatchContext $context): ActionDispatchResult
    {
        $payload = $this->packageDetailsInquiryHandler->buildPayload(
            new PackageDetailsHandlerInput(
                lead: $context->lead,
                conversation: $context->conversation,
                message: $context->message,
                intent: (string) ($decision->finalDecision['intent'] ?? 'tanya_paket'),
            ),
        );

        if ($payload === null) {
            return ActionDispatchResult::continueToResponse();
        }

        $rendered = $this->businessPayloadResponder->render($payload);
        $replyText = trim((string) ($rendered->text ?? ''));

        if ($replyText === '') {
            return ActionDispatchResult::continueToResponse();
        }

        $this->queueAck(
            $context->agent,
            $context->lead,
            $context->conversation,
            $replyText,
            $context->message,
            0,
            $rendered->nextBestAction,
            $rendered->toolResultSummary,
        );

        $context->turnLogger
            ->setResponse('text', $replyText)
            ->setNextBestAction($rendered->nextBestAction)
            ->setTool('grounded_package_reply');

        return ActionDispatchResult::stop(shouldRefreshSummary: true);
    }

    private function dispatchFallback(FinalTurnDecision $decision, TurnDispatchContext $context): ActionDispatchResult
    {
        $fallback = $this->contextAwareFallbackBuilder->build(
            $context->lead,
            $context->conversation,
            $context->message,
            $context->interpretation,
            $context->classifier,
            (string) ($decision->finalDecision['fallback_reason'] ?? 'context_aware_fallback'),
        );

        $fallbackMessage = trim((string) ($fallback['message'] ?? ''));
        if ($fallbackMessage === '') {
            return ActionDispatchResult::stop(
                noReplyReason: (string) ($decision->finalDecision['fallback_reason'] ?? 'fallback_empty_message'),
            );
        }

        $this->queueAck(
            $context->agent,
            $context->lead,
            $context->conversation,
            $fallbackMessage,
            $context->message,
            0,
            (string) ($fallback['next_best_action'] ?? 'respond_to_user'),
            (string) ($fallback['reason'] ?? ($decision->finalDecision['fallback_reason'] ?? 'context_aware_fallback')),
        );

        $context->turnLogger
            ->markFallback((string) ($fallback['reason'] ?? 'context_aware_fallback'))
            ->setResponse('fallback', $fallbackMessage)
            ->setNextBestAction((string) ($fallback['next_best_action'] ?? 'respond_to_user'));

        return ActionDispatchResult::stop(shouldRefreshSummary: true);
    }

    private function queueAck(
        WhatsAppAgent $agent,
        Lead $lead,
        Conversation $conversation,
        string $message,
        Message $inboundMessage,
        int $extraDelay = 0,
        ?string $nextBestAction = null,
        ?string $toolResultSummary = null,
    ): void {
        $message = $this->humanizerService->humanize($message, $lead, $conversation, $inboundMessage);
        $delay = $this->delayPolicyService->getDelay($message) + $extraDelay;

        $this->outboundDispatchService->queueSend(
            agent: $agent,
            to: $lead->preferredWhatsAppRecipient(),
            content: $message,
            queue: 'high',
            delaySeconds: $delay,
            idempotencyKey: $this->buildOutboundIdempotencyKey(
                $conversation,
                $inboundMessage,
                $message,
                $toolResultSummary,
            ),
        );
        $this->conversationStateService->recordOutboundMessage(
            $conversation,
            $lead,
            $message,
            $nextBestAction,
            $toolResultSummary,
        );
    }

    private function mapHandoffReason(?string $reason): HandoffReason
    {
        return match ($reason) {
            'availability', 'availability_check' => HandoffReason::AvailabilityCheck,
            'custom_package' => HandoffReason::CustomPackage,
            'ready_to_book' => HandoffReason::ReadyToBook,
            'payment_proof' => HandoffReason::PaymentProof,
            'complaint' => HandoffReason::Complaint,
            'negative_sentiment' => HandoffReason::NegativeSentiment,
            default => HandoffReason::Other,
        };
    }

    private function handoffAcknowledgment(HandoffReason $reason): string
    {
        return match ($reason) {
            HandoffReason::AvailabilityCheck => 'Untuk mengecek ketersediaan tanggal tersebut, kami perlu konfirmasi langsung dengan tim. Admin kami akan segera membalasmu ya.',
            HandoffReason::CustomPackage => 'Terima kasih sudah menginformasikan kebutuhanmu. Admin kami akan menghubungi untuk mendiskusikan paket custom yang sesuai.',
            HandoffReason::PaymentProof => 'Terima kasih, bukti pembayarannya sudah kami terima. Admin akan mengkonfirmasi dalam waktu dekat ya.',
            HandoffReason::Complaint => 'Kami mohon maaf atas ketidaknyamanannya. Admin kami akan segera menghubungi kamu untuk menyelesaikan masalah ini.',
            default => 'Pesan kamu sudah kami terima. Tim kami akan segera menghubungi kamu ya.',
        };
    }

    private function nextStageFromDecision(Lead $lead, string $intent): LeadStatus
    {
        $current = $lead->status;

        return match (true) {
            $intent === 'ready_to_book' || $intent === 'payment_proof' => LeadStatus::Hot,
            in_array($intent, ['tanya_harga', 'tanya_paket', 'bandingkan_paket'], true)
                => $current === LeadStatus::New ? LeadStatus::Qualified : LeadStatus::Interested,
            default => $current,
        };
    }

    private function createPricelistMissingHandoff(Lead $lead, Conversation $conversation): void
    {
        $alreadyPending = $conversation->handoffRequests()
            ->pending()
            ->exists();

        if ($alreadyPending) {
            return;
        }

        $this->handoffRequestService->create(
            $lead,
            $conversation,
            HandoffReason::Other,
            'pricelist_missing',
            'Lead meminta harga/paket tetapi tenant belum memiliki file pricelist PDF aktif di folder pricelist.',
        );
    }

    private function bookingSchemaFailureMarker(Message $message): string
    {
        return 'booking_schema_error:message:' . $message->id;
    }

    private function buildOutboundIdempotencyKey(
        Conversation $conversation,
        Message $inboundMessage,
        string $outboundContent,
        ?string $context = null,
    ): string {
        $basis = implode('|', [
            'conversation:' . $conversation->id,
            'inbound:' . ($inboundMessage->wa_message_id ?: $inboundMessage->id),
            'context:' . ($context ?: 'reply'),
            'payload:' . hash('sha256', trim($outboundContent)),
        ]);

        return 'wa-out-' . substr(hash('sha256', $basis), 0, 48);
    }
}
