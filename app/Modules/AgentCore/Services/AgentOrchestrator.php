<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\Dispatch\ActionDispatcher;
use App\Modules\AgentCore\Dispatch\TurnDispatchContext;
use App\Modules\AgentCore\Contracts\LlmClientInterface;
use App\Modules\AgentCore\Contracts\TurnDecisionServiceInterface;
use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\AgentCore\DTOs\FinalTurnDecision;
use App\Modules\AgentCore\DTOs\InterpretationResult;
use App\Modules\AgentCore\DTOs\SharedConversationContext;
use App\Modules\AgentCore\DTOs\TurnDecisionInput;
use App\Modules\AgentCore\Enums\FinalAction;
use App\Modules\AgentCore\Enums\LlmMode;
use App\Modules\AgentCore\Enums\TurnOutcomeType;
use App\Modules\AgentCore\Exceptions\InvalidClassifierOutputException;
use App\Modules\AgentCore\Support\AgentLog;
use App\Modules\AgentCore\Support\DecisionTrace;
use App\Modules\Conversations\Actions\RecordAskedFieldAction;
use App\Modules\Conversations\Enums\ConversationStage;
use App\Modules\Conversations\Enums\HandoffReason;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\Message;
use App\Modules\Conversations\Services\ConversationStageService;
use App\Modules\Conversations\Services\ConversationStateService;
use App\Modules\Conversations\Services\ConversationSummaryService;
use App\Modules\Conversations\Services\HandoffRequestService;
use App\Modules\Knowledge\Services\KnowledgeRetrievalService;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadMemoryService;
use App\Modules\Leads\Services\LeadService;
use App\Modules\Leads\Services\LeadStageService;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Services\OutboundDispatchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AgentOrchestrator
{
    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly ContextAssembler $contextAssembler,
        private readonly GuardrailService $guardrailService,
        private readonly HumanizerService $humanizerService,
        private readonly QualityFilterService $qualityFilterService,
        private readonly RiskPolicyService $riskPolicyService,
        private readonly DelayPolicyService $delayPolicyService,
        private readonly LeadService $leadService,
        private readonly LeadMemoryService $leadMemoryService,
        private readonly LeadStageService $leadStageService,
        private readonly HandoffRequestService $handoffRequestService,
        private readonly OutboundDispatchService $outboundDispatchService,
        private readonly ConversationSummaryService $conversationSummaryService,
        private readonly KnowledgeRetrievalService $knowledgeRetrievalService,
        private readonly ConversationStageService $conversationStageService,
        private readonly RecordAskedFieldAction $recordAskedFieldAction,
        private readonly ConversationStateService $conversationStateService,
        private readonly ConversationInterpretationService $conversationInterpretationService,
        private readonly ContextAwareFallbackBuilder $contextAwareFallbackBuilder,
        private readonly FallbackGuardService $fallbackGuardService,
        private readonly ResponseEvaluatorService $responseEvaluatorService,
        private readonly TurnDecisionServiceInterface $turnDecisionService,
        private readonly ActionDispatcher $actionDispatcher,
    ) {}

    public function handleInbound(Message $message, Lead $lead, Conversation $conv, ?string $traceId = null): void
    {
        $turnLogger = new ConversationTurnLogger($traceId);
        $turnLogger->bindMessage($message, $conv);

        AgentLog::info('turn.started', [
            'conv' => $conv->id,
            'msg' => $message->id,
            'stage_before' => $conv->stage instanceof \BackedEnum ? $conv->stage->value : $conv->stage,
            'content_excerpt' => \Illuminate\Support\Str::limit((string) $message->content, 120, ''),
        ]);

        try {
            $this->runHandleInbound($message, $lead, $conv, $turnLogger);
        } finally {
            try {
                $fresh = $conv->fresh();
                $turnLogger->setStageAfter($fresh?->stage ?? $conv->stage);
                $this->evaluateIfApplicable($turnLogger, $fresh ?? $conv);
                $turnLogger->flush();
            } catch (Throwable $e) {
                Log::warning('[AgentOrchestrator] Turn log flush failed', ['error' => $e->getMessage()]);
            }
        }
    }

    private function evaluateIfApplicable(ConversationTurnLogger $logger, Conversation $conv): void
    {
        $replyExcerpt = $logger->getReplyExcerpt();
        if ($replyExcerpt === null) {
            return;
        }

        $state = $conv->state()->first();

        $score = $this->responseEvaluatorService->evaluate(
            $replyExcerpt,
            $conv,
            null,
            $state,
            $logger->getResponseType() ?? 'text',
        );

        $logger->setEvaluatorScore($score);
    }

    private function runHandleInbound(Message $message, Lead $lead, Conversation $conv, ConversationTurnLogger $turnLogger): void
    {
        $this->conversationStateService->recordInboundMessage($message);
        $ruleInterpretation = $this->conversationInterpretationService->interpret((string) $message->content);
        $ruleInterpretation = $this->continueInterpretationFromHistory($message, $conv, $ruleInterpretation);

        $guard = $this->guardrailService->check($lead, $conv);
        if ($guard->blocked) {
            Log::info('[AgentOrchestrator] Guardrail blocked', [
                'tenant_id'      => $lead->tenant_id,
                'lead_id'        => $lead->id,
                'reason'         => $guard->reason,
            ]);
            $this->logNoReplyExit($lead, $conv, $message, 'guardrail_blocked', [
                'guardrail_reason' => $guard->reason,
            ]);
            return;
        }

        $agent = $conv->whatsapp_agent_id ? WhatsAppAgent::find($conv->whatsapp_agent_id) : null;
        if (! $agent) {
            Log::warning('[AgentOrchestrator] No agent to dispatch with', [
                'conversation_id' => $conv->id,
            ]);
            $this->logNoReplyExit($lead, $conv, $message, 'missing_whatsapp_agent', [
                'whatsapp_agent_id' => $conv->whatsapp_agent_id,
            ]);
            return;
        }

        $usedDirectPricelistShortcut = $this->matchesDirectPricelistShortcut($message, $conv);

        if ($usedDirectPricelistShortcut) {
            Log::info('[AgentOrchestrator] Direct pricelist inquiry detected', [
                'tenant_id' => $lead->tenant_id,
                'lead_id' => $lead->id,
                'conversation_id' => $conv->id,
                'message_id' => $message->id,
                'content' => $message->content,
            ]);
            $this->leadStageService->advanceStage($lead, $lead->status === LeadStatus::New ? LeadStatus::Qualified : LeadStatus::Interested);
            $this->conversationStageService->promoteForDirectPricelistInquiry($conv);
            $conv->refresh();
        }

        $intentResolution = [
            'raw_analyzer_intent' => $ruleInterpretation->legacyIntent,
            'rule_intent' => $ruleInterpretation->legacyIntent,
            'final_intent' => $ruleInterpretation->legacyIntent,
            'override_reason' => 'rule_only_fallback',
        ];

        if ($usedDirectPricelistShortcut) {
            $interpretation = $ruleInterpretation->hasClearIntent()
                ? $ruleInterpretation
                : new InterpretationResult(
                    canonicalIntent: 'price_inquiry',
                    legacyIntent: 'tanya_harga',
                    slots: $ruleInterpretation->slots,
                    confidence: max($ruleInterpretation->confidence, 0.92),
                    source: 'shortcut+rules',
                );
            $classifier = $this->conversationInterpretationService->toClassifierOutput($interpretation, $conv->stageEnum());
            $intentResolution = [
                'raw_analyzer_intent' => $interpretation->legacyIntent,
                'rule_intent' => $interpretation->legacyIntent,
                'final_intent' => $classifier->intent,
                'override_reason' => 'direct_pricelist_shortcut',
            ];
        } else {
            try {
                $classifier = $this->runClassifier($lead, $conv);
                $interpretation = $this->conversationInterpretationService->interpret((string) $message->content, $classifier);
                $interpretation = $this->continueInterpretationFromHistory($message, $conv, $interpretation);
                $intentResolution = $this->conversationInterpretationService->resolveClassifierOutput($classifier, $interpretation, [
                    'protect_analyzer_intents' => ['complaint', 'objection_handling', 'clarification'],
                    'block_rule_ready_to_book' => true,
                ]);
                $classifier = $intentResolution['classifier'];
            } catch (Throwable $e) {
                Log::error('[AgentOrchestrator] Classifier failed', [
                    'tenant_id' => $lead->tenant_id,
                    'lead_id'   => $lead->id,
                    'error'     => $e->getMessage(),
                ]);
                AgentLog::warning('classifier.failed', ['error' => $e->getMessage()]);

                if (! $ruleInterpretation->hasClearIntent()) {
                    Log::warning('[AgentOrchestrator] Classifier fallback selected', [
                        'tenant_id' => $lead->tenant_id,
                        'lead_id' => $lead->id,
                        'conversation_id' => $conv->id,
                        'behavior' => 'stage_aware_fallback_reply',
                        'reason' => $e->getMessage(),
                    ]);
                    $turnLogger->markFallback('classifier_failed_no_rule_intent');

                    $fallback = $this->queueControlledClassifierFallbackReply(
                        $lead,
                        $conv,
                        $agent,
                        $message,
                        $ruleInterpretation,
                        'classifier_invalid_output_unclear_rules',
                    );

                    if ($fallback !== null) {
                        $turnLogger
                            ->setResponse('fallback', $fallback['message'])
                            ->setNextBestAction($fallback['next_best_action']);
                        $this->dispatchSummaryRefresh($conv);
                        return;
                    }

                    $this->logNoReplyExit($lead, $conv, $message, 'classifier_failed_no_rule_intent_no_dispatch', [
                        'error' => $e->getMessage(),
                    ]);
                    return;
                }

                Log::warning('[AgentOrchestrator] Classifier fallback selected', [
                    'tenant_id' => $lead->tenant_id,
                    'lead_id' => $lead->id,
                    'conversation_id' => $conv->id,
                    'behavior' => 'intent_aware_rule_fallback',
                    'reason' => $e->getMessage(),
                ]);
                $interpretation = $ruleInterpretation;
                $classifier = $this->conversationInterpretationService->toClassifierOutput($interpretation, $conv->stageEnum());
                $intentResolution = [
                    'raw_analyzer_intent' => 'classifier_failed',
                    'rule_intent' => $interpretation->legacyIntent,
                    'final_intent' => $classifier->intent,
                    'override_reason' => 'classifier_failed_using_rule',
                ];
                $turnLogger->markFallback('classifier_failed_using_rule');
            }

            $intentResolution = $this->applyIntentGuards(
                $classifier,
                $message,
                $conv,
                (string) ($intentResolution['raw_analyzer_intent'] ?? $classifier->intent),
                $intentResolution['rule_intent'] ?? $interpretation->legacyIntent,
                $intentResolution['override_reason'] ?? null,
            );
            $classifier = $intentResolution['classifier'];
        }

        $turnLogger
            ->setIntent($classifier->intent, $classifier->confidence)
            ->setExtractedSlots($classifier->extractedFields ?? []);
        AgentLog::info('intent.resolution', [
            'raw_analyzer_intent' => $intentResolution['raw_analyzer_intent'],
            'rule_intent' => $intentResolution['rule_intent'],
            'final_intent' => $intentResolution['final_intent'],
            'override_reason' => $intentResolution['override_reason'],
            'override_rejected_reason' => $intentResolution['override_rejected_reason'] ?? null,
        ]);
        AgentLog::info('classifier.done', [
            'intent' => $classifier->intent,
            'confidence' => $classifier->confidence,
            'needs_handoff' => $classifier->needsHandoff,
        ]);

        $this->riskPolicyService->calculateRisk($lead, $classifier, (string) $message->content);
        $lead->refresh();

        $this->leadMemoryService->upsert($lead, $this->mapExtractedFields($classifier->extractedFields));

        // Apply conversation stage transition based on classifier output.
        $this->conversationStageService->decideAndApply(
            $conv,
            $classifier,
            $this->leadMemoryService->getSnapshot($lead),
            (string) $message->content,
        );
        $conv->refresh();
        $this->conversationStateService->applyInterpretationResult($conv, $lead->fresh(), $interpretation, $classifier);

        $decision = $this->decideTurn($lead, $conv, $message, $classifier, $interpretation);
        DecisionTrace::log($decision, ['message_id' => $message->id]);

        $dispatchResult = $this->actionDispatcher->dispatch(
            $decision,
            new TurnDispatchContext(
                lead: $lead,
                conversation: $conv,
                message: $message,
                agent: $agent,
                turnLogger: $turnLogger,
                classifier: $classifier,
                interpretation: $interpretation,
            ),
        );

        if ($dispatchResult->noReplyReason !== null) {
            $this->logNoReplyExit($lead, $conv, $message, $dispatchResult->noReplyReason, [
                'decision_source' => 'turn_decision_service',
            ]);
            return;
        }

        if ($dispatchResult->shouldStop) {
            if ($dispatchResult->shouldRefreshSummary) {
                $this->dispatchSummaryRefresh($conv);
            }

            return;
        }

        $this->leadStageService->advanceStage($lead, $this->nextStageFromIntent($lead, $classifier));

        try {
            $responseText = $this->runResponse($lead, $conv, $classifier->intent);
        } catch (Throwable $e) {
            Log::error('[AgentOrchestrator] Response generation failed', [
                'tenant_id' => $lead->tenant_id,
                'lead_id'   => $lead->id,
                'error'     => $e->getMessage(),
            ]);

            AgentLog::warning('response.generation_failed', ['error' => $e->getMessage()]);
            $turnLogger->markFallback('response_generation_failed')->setResponse('fallback', null);

            if ($this->queueIntentAwareFallbackReply($lead, $conv, $agent, $message, $classifier->intent, $interpretation, $e)) {
                $this->dispatchSummaryRefresh($conv);
                return;
            }

            if ($this->dispatchFallbackReplyIfNeeded($lead, $conv, $message, $classifier, $interpretation, $e)) {
                return;
            }

            return;
        }

        if ($responseText === '') {
            Log::warning('[AgentOrchestrator] Empty response generated, retrying once', [
                'tenant_id' => $lead->tenant_id,
                'lead_id' => $lead->id,
                'conversation_id' => $conv->id,
                'intent' => $classifier->intent,
                'message_id' => $message->id,
            ]);

            $responseText = $this->retryEmptyResponse($lead, $conv, $classifier->intent, $message, $interpretation);
        }

        $rawModelReply = $responseText;
        $guardResult = $this->guardInvalidHandoffLanguage($responseText, $conv, $classifier, $message);
        $responseText = $guardResult['message'];

        $guardedResponse = $this->fallbackGuardService->guardGeneratedReply(
            $responseText,
            $lead,
            $conv,
            $message,
            $classifier,
            $interpretation,
        );

        $nextBestAction = null;
        $toolResultSummary = null;

        if ($guardedResponse !== null) {
            $responseText = $guardedResponse['message'];
            $nextBestAction = $guardedResponse['next_best_action'];
            $toolResultSummary = $guardedResponse['tool_result_summary'];
            $turnLogger->markFallback('fallback_guard_rewrote_reply');
        }

        $qualityFiltered = $this->qualityFilterService->filterGeneratedReply(
            $responseText,
            $lead,
            $conv,
            $message,
            $classifier,
            $interpretation,
            'text',
        );

        if ($qualityFiltered !== null) {
            $responseText = $qualityFiltered['message'];
            $nextBestAction = $qualityFiltered['next_best_action'];
            $toolResultSummary = $qualityFiltered['tool_result_summary'];
            $turnLogger->markFallback('quality_filter_rewrote_reply')
                ->setEvaluatorScore($qualityFiltered['evaluator_score']);

            if (($qualityFiltered['handoff_reason_detail'] ?? null) !== null) {
                $this->createQualityFilterHandoffIfNeeded(
                    $lead,
                    $conv,
                    (string) $qualityFiltered['handoff_reason_detail'],
                    (string) ($qualityFiltered['handoff_summary_for_admin'] ?? ''),
                );
            }
        }

        $postQualityReply = $responseText;
        $humanized = $this->humanizerService->humanizeWithMetadata($responseText, $lead, $conv, $message);
        $responseText = $humanized['message'];
        $preSendValidation = $this->validateReplyTopicBeforeSend(
            (string) $message->content,
            $classifier,
            $interpretation,
            $rawModelReply,
            $responseText,
        );
        $responseText = $preSendValidation['message'];

        $this->logResponsePipeline(
            $lead,
            $conv,
            $message,
            $classifier,
            $interpretation,
            $rawModelReply,
            $guardResult,
            $guardedResponse,
            $postQualityReply,
            $qualityFiltered,
            $humanized,
            $preSendValidation,
            $responseText,
        );

        $delay = $this->delayPolicyService->getDelay($responseText);

        $turnLogger
            ->setResponse('text', $responseText)
            ->setNextBestAction($nextBestAction);

        $this->maybeRecordDiscoveryAsk($lead, $conv, $message, $responseText);

        $this->outboundDispatchService->queueSend(
            agent:          $agent,
            to:             $lead->preferredWhatsAppRecipient(),
            content:        $responseText,
            queue:          'high',
            delaySeconds:   $delay,
            idempotencyKey: $this->buildOutboundIdempotencyKey($conv, $message, $responseText),
        );
        $this->conversationStateService->recordOutboundMessage(
            $conv,
            $lead,
            $responseText,
            $nextBestAction,
            $toolResultSummary,
        );

        $this->dispatchSummaryRefresh($conv);
    }

    public function runClassifier(Lead $lead, Conversation $conv): ClassifierOutput
    {
        $messages = $this->contextAssembler->assemble($lead, $conv, LlmMode::Classifier);

        $response = $this->llm->complete($messages, [
            'tenant_id'       => $lead->tenant_id,
            'conversation_id' => $conv->id,
            'trace_id'        => $this->currentTraceId(),
            'mode'            => LlmMode::Classifier,
            'temperature'     => 0.1,
            'max_tokens'      => 300,
        ]);

        $rawContent = trim($response->content);

        Log::info('[AgentOrchestrator] Classifier raw response received', [
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'conversation_id' => $conv->id,
            'raw_response' => $rawContent,
        ]);

        try {
            return ClassifierOutput::fromArray($this->parseJsonContent($rawContent));
        } catch (Throwable $e) {
            Log::warning('[AgentOrchestrator] Invalid classifier output rejected', [
                'tenant_id' => $lead->tenant_id,
                'lead_id' => $lead->id,
                'conversation_id' => $conv->id,
                'raw_response' => $rawContent,
                'reason' => $e->getMessage(),
            ]);
            AgentLog::warning('classifier.invalid_output', [
                'reason' => $e->getMessage(),
            ]);

            throw new InvalidClassifierOutputException(
                'Classifier output rejected: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    public function runResponse(Lead $lead, Conversation $conv, string $intent): string
    {
        $messages = $this->contextAssembler->assemble($lead, $conv, LlmMode::Response, $intent);

        $response = $this->llm->complete($messages, [
            'tenant_id'       => $lead->tenant_id,
            'conversation_id' => $conv->id,
            'trace_id'        => $this->currentTraceId(),
            'mode'            => LlmMode::Response,
            'temperature'     => 0.7,
            'max_tokens'      => 400,
        ]);

        return trim($response->content);
    }

    private function currentTraceId(): ?string
    {
        if (! method_exists(\Illuminate\Support\Facades\Log::class, 'sharedContext')) {
            return null;
        }
        try {
            return \Illuminate\Support\Facades\Log::sharedContext()['trace_id'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    public function runFollowUp(Lead $lead, Conversation $conv): string
    {
        $messages = $this->contextAssembler->assemble($lead, $conv, LlmMode::FollowUp, 'follow_up');

        $response = $this->llm->complete($messages, [
            'tenant_id'       => $lead->tenant_id,
            'conversation_id' => $conv->id,
            'mode'            => LlmMode::FollowUp,
            'temperature'     => 0.6,
            'max_tokens'      => 220,
        ]);

        return trim($response->content);
    }

    /**
     * @return array{message: string, rewrite_reason: string|null}
     */
    private function guardInvalidHandoffLanguage(
        string $responseText,
        Conversation $conversation,
        ClassifierOutput $classifier,
        Message $message,
    ): array {
        $responseText = trim($responseText);
        if ($responseText === '') {
            return [
                'message' => $responseText,
                'rewrite_reason' => null,
            ];
        }

        if (! $this->containsHandoffLanguage($responseText)) {
            return [
                'message' => $responseText,
                'rewrite_reason' => null,
            ];
        }

        if ($this->canMentionHandoffInReply($conversation, $classifier)) {
            return [
                'message' => $responseText,
                'rewrite_reason' => null,
            ];
        }

        $sanitized = $this->stripHandoffSentences($responseText);

        if ($sanitized !== '') {
            Log::info('[AgentOrchestrator] Removed invalid handoff language from reply', [
                'conversation_id' => $conversation->id,
                'classifier_intent' => $classifier->intent,
                'stage' => $conversation->stage,
            ]);

            return [
                'message' => $sanitized,
                'rewrite_reason' => 'strip_invalid_handoff_language',
            ];
        }

        Log::warning('[AgentOrchestrator] Invalid handoff language forced fallback reply', [
            'conversation_id' => $conversation->id,
            'classifier_intent' => $classifier->intent,
            'stage' => $conversation->stage,
        ]);

        $lead = $conversation->lead ?? $message->lead;
        if (! $lead) {
            return [
                'message' => 'Siap, aku bantu ya. Mau kita lanjut bahas paket yang cocok atau langkah berikutnya dulu?',
                'rewrite_reason' => 'invalid_handoff_language_fallback_no_lead',
            ];
        }

        $fallback = $this->contextAwareFallbackBuilder->build(
            $lead,
            $conversation,
            $message,
            null,
            $classifier,
            'invalid_handoff_language_fallback',
        );

        return [
            'message' => $fallback['message'],
            'rewrite_reason' => 'invalid_handoff_language_fallback',
        ];
    }

    /**
     * @param  array{message: string, rewrite_reason: string|null}  $guardResult
     * @param  array{message: string, next_best_action: string, tool_result_summary: string, rewrite_reason: string}|null  $fallbackResult
     * @param  array{message: string, next_best_action: string, tool_result_summary: string, rewrite_reason: string, evaluator_score: array, handoff_reason_detail?: string, handoff_summary_for_admin?: string}|null  $qualityResult
     * @param  array{message: string, reasons: list<string>}  $humanized
     * @param  array{message: string, topic_changed: bool, user_latest_topic: string, topic_change_reason: ?string}  $preSendValidation
     */
    private function logResponsePipeline(
        Lead $lead,
        Conversation $conversation,
        Message $message,
        ?ClassifierOutput $classifier,
        ?InterpretationResult $interpretation,
        string $rawModelReply,
        array $guardResult,
        ?array $fallbackResult,
        string $postQualityReply,
        ?array $qualityResult,
        array $humanized,
        array $preSendValidation,
        string $finalReply,
    ): void {
        $userLatestTopic = $this->detectUserLatestTopic((string) $message->content, $classifier, $interpretation);
        $postRefinerReply = $qualityResult['message'] ?? $postQualityReply;

        AgentLog::info('response.pipeline', [
            'lead_id' => $lead->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'user_latest_topic' => $userLatestTopic,
            'raw_model_reply' => $rawModelReply,
            'raw_controller_reply' => $rawModelReply,
            'post_guard_reply' => $guardResult['message'],
            'post_fallback_reply' => $fallbackResult['message'] ?? $guardResult['message'],
            'post_quality_reply' => $postQualityReply,
            'post_refiner_reply' => $postRefinerReply,
            'topic_changed' => $preSendValidation['topic_changed'],
            'topic_change_reason' => $preSendValidation['topic_change_reason'],
            'final_sent_reply' => $finalReply,
            'rewrites' => array_values(array_filter([
                [
                    'stage' => 'guard_invalid_handoff_language',
                    'reason' => $guardResult['rewrite_reason'],
                    'applied' => $guardResult['rewrite_reason'] !== null,
                ],
                [
                    'stage' => 'fallback_guard',
                    'reason' => $fallbackResult['rewrite_reason'] ?? null,
                    'applied' => $fallbackResult !== null,
                ],
                [
                    'stage' => 'quality_filter',
                    'reason' => $qualityResult['rewrite_reason'] ?? null,
                    'applied' => $qualityResult !== null,
                ],
                [
                    'stage' => 'pre_send_validation',
                    'reason' => $preSendValidation['topic_change_reason'],
                    'applied' => $preSendValidation['topic_changed'],
                ],
                [
                    'stage' => 'humanizer',
                    'reason' => $humanized['reasons'] !== [] ? implode(',', $humanized['reasons']) : null,
                    'applied' => $humanized['reasons'] !== [],
                ],
            ], static fn (array $rewrite): bool => $rewrite['applied'] || $rewrite['reason'] !== null)),
        ]);
    }

    public function generateFollowUp(Lead $lead): ?string
    {
        $conv = $lead->conversations()->active()->latest()->first();
        if (! $conv) {
            return null;
        }

        try {
            return $this->runFollowUp($lead, $conv);
        } catch (Throwable $e) {
            Log::error('[AgentOrchestrator] Follow-up generation failed', [
                'tenant_id' => $lead->tenant_id,
                'lead_id'   => $lead->id,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function generateSummary(Conversation $conv): void
    {
        $lead = $conv->lead;
        if (! $lead) {
            return;
        }

        try {
            $messages = $this->contextAssembler->assemble($lead, $conv, LlmMode::Summary);

            $response = $this->llm->complete($messages, [
                'tenant_id'       => $lead->tenant_id,
                'conversation_id' => $conv->id,
                'mode'            => LlmMode::Summary,
                'temperature'     => 0.2,
                'max_tokens'      => 500,
            ]);

            $parsed = $this->parseSummaryOutput($response->content);

            if (! empty($parsed['memory_updates'])) {
                $this->leadMemoryService->upsert($lead, $this->mapExtractedFields($parsed['memory_updates']));
            }

            $conv->summary()->updateOrCreate(
                ['conversation_id' => $conv->id],
                [
                    'tenant_id'                => $conv->tenant_id,
                    'summary_text'             => $parsed['summary'] ?? '',
                    'last_summarized_at'       => now(),
                    'message_count_at_summary' => $conv->messages()->count(),
                ],
            );
        } catch (Throwable $e) {
            Log::error('[AgentOrchestrator] Summary generation failed', [
                'conversation_id' => $conv->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    private function shouldHandleReadyToBook(ClassifierOutput $classifier): bool
    {
        return $classifier->intent === 'ready_to_book'
            || $classifier->handoffReason === 'ready_to_book';
    }

    private function isBookingFieldReplyCandidate(
        Lead $lead,
        ClassifierOutput $classifier,
        Message $message,
    ): bool {
        if (! in_array($lead->status, [LeadStatus::Hot, LeadStatus::ReadyForHuman], true)) {
            return false;
        }

        if ($classifier->needsHandoff || $this->shouldHandleReadyToBook($classifier)) {
            return false;
        }

        if ($classifier->intent !== 'other') {
            return false;
        }

        $content = trim((string) $message->content);
        if ($content === '' || $this->isGreetingMessage(strtolower($content)) || $this->isTestMessage(strtolower($content))) {
            return false;
        }

        return true;
    }

    private function nextStageFromIntent(Lead $lead, ClassifierOutput $c): LeadStatus
    {
        $current = $lead->status;

        return match (true) {
            $c->intent === 'ready_to_book' || $c->intent === 'payment_proof' => LeadStatus::Hot,
            $c->intent === 'tanya_harga' || $c->intent === 'tanya_paket' || $c->intent === 'bandingkan_paket'
                => $current === LeadStatus::New ? LeadStatus::Qualified : LeadStatus::Interested,
            default => $current,
        };
    }

    /**
     * Map classifier field names to LeadMemory schema.
     *
     * @param  array<string, mixed>  $extracted
     * @return array<string, mixed>
     */
    private function mapExtractedFields(array $extracted): array
    {
        $mapped = [];
        foreach ($extracted as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if ($key === 'package_interest') {
                $mapped['preferred_packages'] = [(string) $value];
                $mapped['custom_fields']['package_interest'] = (string) $value;

                continue;
            }

            if (in_array($key, ['pricing_focus', 'payment_topic', 'event_time_start', 'event_time_end'], true)) {
                $mapped['custom_fields'][$key] = (string) $value;

                continue;
            }

            match ($key) {
                'location' => $mapped['event_location'] = $value,
                'budget'   => [$mapped['budget_min'], $mapped['budget_max']] = $this->parseBudget($value),
                default    => $mapped[$key] = $value,
            };
        }
        return $mapped;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function parseBudget(mixed $value): array
    {
        if (is_numeric($value)) {
            $v = (int) $value;
            return [$v, $v];
        }
        if (is_string($value) && preg_match_all('/\d+/', $value, $m)) {
            $nums = array_map('intval', $m[0]);
            if (count($nums) === 1) return [$nums[0], $nums[0]];
            return [min($nums), max($nums)];
        }
        return [null, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonContent(string $content): array
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Classifier JSON response is empty.');
        }

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        if ($trimmed === '') {
            throw new \InvalidArgumentException('Classifier JSON response is empty after removing code fences.');
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Classifier JSON parse failed: ' . $e->getMessage(), 0, $e);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new \InvalidArgumentException('Classifier JSON root must be an object.');
        }

        return $decoded;
    }

    /**
     * @return array{summary: ?string, lead_stage: ?string, memory_updates: array<string, mixed>, follow_up_eligible: ?bool, follow_up_reason: ?string}
     */
    private function parseSummaryOutput(string $content): array
    {
        $result = [
            'summary' => null,
            'lead_stage' => null,
            'memory_updates' => [],
            'follow_up_eligible' => null,
            'follow_up_reason' => null,
        ];

        if (preg_match('/SUMMARY:\s*(.+?)(?=LEAD_STAGE:|$)/si', $content, $m)) {
            $result['summary'] = trim($m[1]);
        }
        if (preg_match('/LEAD_STAGE:\s*(\w+)/i', $content, $m)) {
            $result['lead_stage'] = strtolower(trim($m[1]));
        }
        if (preg_match('/MEMORY_UPDATES:\s*(.+?)(?=FOLLOW_UP_ELIGIBLE:|$)/si', $content, $m)) {
            foreach (preg_split('/\r?\n/', trim($m[1])) as $line) {
                if (preg_match('/^-\s*(\w+):\s*(.+)$/', trim($line), $kv)) {
                    $val = trim($kv[2]);
                    $result['memory_updates'][$kv[1]] = strcasecmp($val, 'null') === 0 ? null : $val;
                }
            }
        }
        if (preg_match('/FOLLOW_UP_ELIGIBLE:\s*(true|false)/i', $content, $m)) {
            $result['follow_up_eligible'] = strcasecmp($m[1], 'true') === 0;
        }
        if (preg_match('/FOLLOW_UP_REASON:\s*(.+)$/im', $content, $m)) {
            $reason = trim($m[1]);
            $result['follow_up_reason'] = $reason !== '' ? $reason : null;
        }

        return $result;
    }

    private function dispatchSummaryRefresh(Conversation $conv): void
    {
        $this->conversationSummaryService->refresh($conv);
    }

    private function canMentionHandoffInReply(Conversation $conversation, ClassifierOutput $classifier): bool
    {
        if ($conversation->stageEnum() === ConversationStage::HandoffToHuman) {
            return true;
        }

        if (! $classifier->needsHandoff) {
            return false;
        }

        return in_array($classifier->handoffReason, [
            'availability',
            'availability_check',
            'custom_package',
            'ready_to_book',
            'payment_proof',
            'complaint',
            'opt_out',
            'negative_sentiment',
        ], true);
    }

    private function containsHandoffLanguage(string $content): bool
    {
        $normalized = strtolower(trim($content));

        if ($normalized === '') {
            return false;
        }

        $hasAdminSubject = str_contains($normalized, 'admin')
            || str_contains($normalized, 'tim kami')
            || str_contains($normalized, 'tim admin');

        $hasHandoffVerb = str_contains($normalized, 'menghubungi')
            || str_contains($normalized, 'hubungi')
            || str_contains($normalized, 'follow up')
            || str_contains($normalized, 'membalas')
            || str_contains($normalized, 'balas nanti')
            || str_contains($normalized, 'lanjut bantu');

        return $hasAdminSubject && $hasHandoffVerb;
    }

    private function stripHandoffSentences(string $content): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($content)) ?? trim($content);
        $parts = preg_split('/(?<=[.!?])\s+/u', $normalized) ?: [];

        $kept = array_values(array_filter($parts, function (string $part): bool {
            return ! $this->containsHandoffLanguage($part);
        }));

        return trim(implode(' ', $kept));
    }

    private function maybeRecordDiscoveryAsk(Lead $lead, Conversation $conv, Message $message, string $finalReply): void
    {
        $stage = $conv->stageEnum();

        if (! in_array($stage, [
            ConversationStage::NewLead,
            ConversationStage::Qualification,
            ConversationStage::NeedsDiscovery,
        ], true)) {
            $this->logAskedFieldDecision($lead, $conv, $message, $finalReply, [
                'decision' => 'skipped',
                'reason' => 'stage_not_eligible',
                'stage' => $stage->value,
            ]);
            return;
        }

        $snapshot = $this->leadMemoryService->getSnapshot($lead);
        $missingFields = $this->conversationStageService->missingDiscoveryFields($conv, $snapshot);
        $askedFieldsBefore = $conv->askedFields();
        $nextExpectedFieldBefore = $conv->next_expected_field;

        if ($missingFields === []) {
            $this->logAskedFieldDecision($lead, $conv, $message, $finalReply, [
                'decision' => 'skipped',
                'reason' => 'no_missing_fields',
                'stage' => $stage->value,
                'asked_fields_before' => $askedFieldsBefore,
                'next_expected_field_before' => $nextExpectedFieldBefore,
            ]);
            return;
        }

        $predictedField = $missingFields[0] ?? null;
        $askedField = $this->detectAskedDiscoveryField($finalReply, $missingFields);

        if ($askedField === null) {
            $this->logAskedFieldDecision($lead, $conv, $message, $finalReply, [
                'decision' => 'skipped',
                'reason' => 'final_reply_did_not_ask_missing_field',
                'stage' => $stage->value,
                'predicted_field' => $predictedField,
                'candidate_fields' => $missingFields,
                'asked_fields_before' => $askedFieldsBefore,
                'next_expected_field_before' => $nextExpectedFieldBefore,
            ]);
            return;
        }

        $followingField = $this->conversationStageService->nextExpectedFieldAfterAsking($conv, $snapshot, $askedField);
        $this->recordAskedFieldAction->execute($conv, $askedField, $followingField);

        $this->logAskedFieldDecision($lead, $conv, $message, $finalReply, [
            'decision' => 'recorded',
            'reason' => 'final_reply_asks_field',
            'stage' => $stage->value,
            'predicted_field' => $predictedField,
            'recorded_field' => $askedField,
            'next_expected_field_after_record' => $followingField,
            'candidate_fields' => $missingFields,
            'asked_fields_before' => $askedFieldsBefore,
            'next_expected_field_before' => $nextExpectedFieldBefore,
            'asked_fields_after' => $conv->askedFields(),
        ]);
    }

    /**
     * @param  list<string>  $candidateFields
     */
    private function detectAskedDiscoveryField(string $reply, array $candidateFields): ?string
    {
        $question = $this->extractFinalQuestionEvidence($reply);
        if ($question === null) {
            return null;
        }

        foreach ($candidateFields as $field) {
            if ($this->questionTargetsField($question, $field)) {
                return $field;
            }
        }

        return null;
    }

    private function extractFinalQuestionEvidence(string $reply): ?string
    {
        $normalized = mb_strtolower(trim($reply));
        if ($normalized === '') {
            return null;
        }

        $segments = preg_split('/(?<=[.!?])\s+/u', $normalized) ?: [$normalized];
        $questions = array_values(array_filter($segments, static fn (string $segment): bool => str_contains($segment, '?')));

        if ($questions !== []) {
            return trim(implode(' ', $questions));
        }

        if (preg_match('/\b(boleh|bisa|mohon|tolong|kirim|info|sebutkan|share)\b/u', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    private function questionTargetsField(string $question, string $field): bool
    {
        $patterns = match ($field) {
            'service_type' => [
                'layanan yang dicari',
                'layanan yang kamu cari',
                'layanan yang kakak cari',
                'fokus foto',
                'foto, video, atau keduanya',
                'foto, video atau keduanya',
                'foto atau video',
            ],
            'event_date' => [
                'tanggal acara',
                'tanggalnya',
                'tanggal berapa',
                'tanggal kapan',
            ],
            'location' => [
                'lokasi acara',
                'lokasinya',
                'di kota mana',
                'di mana acaranya',
                'dimana acaranya',
            ],
            'guest_count' => [
                'jumlah tamu',
                'perkiraan jumlah tamu',
                'berapa tamu',
            ],
            'budget' => [
                'budget yang disiapkan',
                'budgetnya',
                'anggaran yang disiapkan',
                'budget',
                'anggaran',
            ],
            'name' => [
                'nama lengkap',
                'namanya siapa',
                'siapa nama',
            ],
            default => [str_replace('_', ' ', $field)],
        };

        foreach ($patterns as $pattern) {
            if (str_contains($question, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logAskedFieldDecision(
        Lead $lead,
        Conversation $conv,
        Message $message,
        string $finalReply,
        array $context,
    ): void {
        AgentLog::info('asked_fields.decision', array_merge([
            'lead_id' => $lead->id,
            'conversation_id' => $conv->id,
            'message_id' => $message->id,
            'final_reply' => $finalReply,
        ], $context));
    }

    private function canAutoSendPricelistForStage(Conversation $conversation): bool
    {
        return in_array($conversation->stageEnum(), [
            ConversationStage::PackageRecommendation,
            ConversationStage::ObjectionHandling,
            ConversationStage::PaymentDiscussion,
            ConversationStage::Closing,
        ], true);
    }

    private function canGroundPackageAnswerForStage(Conversation $conversation): bool
    {
        return in_array($conversation->stageEnum(), [
            ConversationStage::PackageRecommendation,
            ConversationStage::ObjectionHandling,
            ConversationStage::PaymentDiscussion,
            ConversationStage::Closing,
            ConversationStage::FollowUp,
        ], true);
    }

    private function matchesDirectPricelistShortcut(Message $message, ?Conversation $conversation = null): bool
    {
        $content = strtolower(trim((string) $message->content));

        if ($content === '') {
            return false;
        }

        if ($this->prefersTextPricingExplanation($message, $conversation)) {
            return false;
        }

        if ($this->containsDirectPricelistKeywords($content)) {
            return true;
        }

        return $conversation !== null
            && ($this->isPricelistDocumentFollowUp($message, $conversation)
                || $this->isAffirmingRecentPricelistOffer($message, $conversation));
    }

    private function detectDirectPackageSignal(Message $message): bool
    {
        $content = mb_strtolower(trim((string) $message->content));

        if ($content === '' || $this->containsDirectPricelistKeywords($content)) {
            return false;
        }

        $asksPackage = str_contains($content, 'paket')
            || str_contains($content, 'package')
            || str_contains($content, 'isi paket')
            || str_contains($content, 'detail paket')
            || str_contains($content, 'coverage')
            || str_contains($content, 'silver')
            || str_contains($content, 'gold')
            || str_contains($content, 'platinum')
            || str_contains($content, 'premium')
            || str_contains($content, 'basic');

        $asksPriceExplicitly = str_contains($content, 'harga')
            || str_contains($content, 'pricelist')
            || str_contains($content, 'price list')
            || str_contains($content, 'daftar harga')
            || str_contains($content, 'biaya');

        return $asksPackage && ! $asksPriceExplicitly;
    }

    private function dispatchFallbackReplyIfNeeded(
        Lead $lead,
        Conversation $conv,
        Message $message,
        ?ClassifierOutput $classifier,
        ?InterpretationResult $interpretation,
        Throwable $e,
    ): bool
    {
        if (! $this->isTransientLlmFailure($e)) {
            $this->logNoReplyExit($lead, $conv, $message, 'fallback_not_dispatched_non_transient_error', [
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
            ]);
            return false;
        }

        $agent = $conv->whatsapp_agent_id ? WhatsAppAgent::find($conv->whatsapp_agent_id) : null;
        if (! $agent || ! $agent->isConnected()) {
            $this->logNoReplyExit($lead, $conv, $message, 'fallback_not_dispatched_agent_unavailable', [
                'whatsapp_agent_id' => $conv->whatsapp_agent_id,
                'agent_found' => $agent !== null,
                'agent_connected' => $agent?->isConnected() ?? false,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $fallback = $this->contextAwareFallbackBuilder->build(
            $lead,
            $conv,
            $message,
            $interpretation,
            $classifier,
            'transient_failure_context_fallback',
        );
        $fallbackMessage = $this->humanizerService->humanize($fallback['message'], $lead, $conv, $message);

        $this->outboundDispatchService->queueSend(
            agent: $agent,
            to: $lead->preferredWhatsAppRecipient(),
            content: $fallbackMessage,
            queue: 'high',
            delaySeconds: $this->delayPolicyService->getDelay($fallbackMessage),
            idempotencyKey: $this->buildOutboundIdempotencyKey($conv, $message, $fallbackMessage, $fallback['reason'] ?? 'transient_failure_fallback'),
        );
        $this->conversationStateService->recordOutboundMessage(
            $conv,
            $lead,
            $fallbackMessage,
            $fallback['next_best_action'],
            $fallback['reason'],
        );

        AgentLog::info('classifier.fallback_reply_queued', [
            'lead_id' => $lead->id,
            'conversation_id' => $conv->id,
            'message_id' => $message->id,
            'reason' => $fallback['reason'],
            'next_best_action' => $fallback['next_best_action'],
        ]);

        return true;
    }

    /**
     * @return array{message: string, next_best_action: string, reason: string}|null
     */
    private function queueControlledClassifierFallbackReply(
        Lead $lead,
        Conversation $conv,
        WhatsAppAgent $agent,
        Message $message,
        InterpretationResult $interpretation,
        string $reason,
    ): ?array {
        $fallback = $this->contextAwareFallbackBuilder->build(
            $lead,
            $conv,
            $message,
            $interpretation,
            null,
            $reason,
        );

        if (trim((string) ($fallback['message'] ?? '')) === '') {
            $this->logNoReplyExit($lead, $conv, $message, 'classifier_fallback_empty_message', [
                'fallback_reason' => $reason,
            ]);
            return null;
        }

        $fallbackMessage = $this->humanizerService->humanize($fallback['message'], $lead, $conv, $message);

        $this->outboundDispatchService->queueSend(
            agent: $agent,
            to: $lead->preferredWhatsAppRecipient(),
            content: $fallbackMessage,
            queue: 'high',
            delaySeconds: $this->delayPolicyService->getDelay($fallbackMessage),
            idempotencyKey: $this->buildOutboundIdempotencyKey($conv, $message, $fallbackMessage, $fallback['reason'] ?? $reason),
        );
        $this->conversationStateService->recordOutboundMessage(
            $conv,
            $lead,
            $fallbackMessage,
            $fallback['next_best_action'],
            $fallback['reason'],
        );

        AgentLog::info('classifier.fallback_reply_queued', [
            'lead_id' => $lead->id,
            'conversation_id' => $conv->id,
            'message_id' => $message->id,
            'reason' => $fallback['reason'],
            'next_best_action' => $fallback['next_best_action'],
        ]);

        return [
            'message' => $fallbackMessage,
            'next_best_action' => $fallback['next_best_action'],
            'reason' => $fallback['reason'],
        ];
    }

    private function queueGroundedPackageReply(
        Lead $lead,
        Conversation $conv,
        WhatsAppAgent $agent,
        Message $message,
    ): ?string {
        $items = $this->resolveGroundedPackageItems($lead, $conv);

        if ($items->isEmpty()) {
            return null;
        }

        $reply = $this->humanizerService->humanize(
            $this->buildGroundedPackageReply($conv, $items),
            $lead,
            $conv,
            $message,
        );

        $this->outboundDispatchService->queueSend(
            agent: $agent,
            to: $lead->preferredWhatsAppRecipient(),
            content: $reply,
            queue: 'high',
            delaySeconds: $this->delayPolicyService->getDelay($reply),
            idempotencyKey: $this->buildOutboundIdempotencyKey($conv, $message, $reply, 'grounded_package_answer'),
        );
        $this->conversationStateService->recordOutboundMessage(
            $conv,
            $lead,
            $reply,
            'respond_to_user',
            'grounded_package_answer',
        );

        AgentLog::info('package.grounded_reply_queued', [
            'lead_id' => $lead->id,
            'conversation_id' => $conv->id,
            'message_id' => $message->id,
            'package_titles' => $items->pluck('title')->values()->all(),
        ]);

        return $reply;
    }

    private function queueIntentAwareFallbackReply(
        Lead $lead,
        Conversation $conv,
        WhatsAppAgent $agent,
        Message $message,
        string $intent,
        InterpretationResult $interpretation,
        Throwable $e,
    ): bool {
        if (! $this->isTransientLlmFailure($e) || ! $interpretation->hasClearIntent()) {
            return false;
        }

        $fallback = $this->contextAwareFallbackBuilder->build(
            $lead,
            $conv,
            $message,
            $interpretation,
            null,
            'intent_safe_fallback',
        );
        if ($fallback['message'] === '') {
            return false;
        }
        $fallbackMessage = $this->humanizerService->humanize($fallback['message'], $lead, $conv, $message);

        $this->outboundDispatchService->queueSend(
            agent: $agent,
            to: $lead->preferredWhatsAppRecipient(),
            content: $fallbackMessage,
            queue: 'high',
            delaySeconds: $this->delayPolicyService->getDelay($fallbackMessage),
            idempotencyKey: $this->buildOutboundIdempotencyKey($conv, $message, $fallbackMessage, $fallback['reason'] ?? 'intent_safe_fallback'),
        );
        $this->conversationStateService->recordOutboundMessage(
            $conv,
            $lead,
            $fallbackMessage,
            $fallback['next_best_action'],
            $fallback['reason'],
        );

        return true;
    }

    private function resolveGroundedPackageItems(Lead $lead, Conversation $conv): Collection
    {
        $state = $conv->state()->first();
        $filledSlots = is_array($state?->filled_slots) ? $state->filled_slots : [];
        $packageInterest = $this->stringOrNull($filledSlots['package_interest'] ?? null);
        $eventType = $this->stringOrNull($filledSlots['event_type'] ?? null);

        return $this->knowledgeRetrievalService->getPackageSubset(
            $lead->tenant,
            $packageInterest,
            $eventType,
            3,
        );
    }

    private function buildGroundedPackageReply(Conversation $conv, Collection $items): string
    {
        $state = $conv->state()->first();
        $filledSlots = is_array($state?->filled_slots) ? $state->filled_slots : [];
        $eventType = $this->stringOrNull($filledSlots['event_type'] ?? null);
        $packageInterest = $this->stringOrNull($filledSlots['package_interest'] ?? null);

        $scope = $packageInterest !== null
            ? 'paket ' . $packageInterest
            : ($eventType !== null ? 'paket ' . $eventType : 'paket yang tersedia');

        $primaryItem = $this->selectPrimaryGroundedPackageItem($items, $eventType, $packageInterest);
        $structuredReply = $primaryItem !== null
            ? $this->buildStructuredGroundedPackageReply($scope, $primaryItem, $packageInterest)
            : null;

        if ($structuredReply !== null) {
            return $structuredReply;
        }

        $catalog = $items->map(function ($item): string {
            $title = trim((string) ($item->title ?? 'Paket'));
            $summary = $this->summarizePackageKnowledge((string) ($item->content ?? ''));

            return sprintf('%s: %s.', $title, rtrim($summary, '.'));
        })->implode(' ');

        return sprintf(
            'Untuk %s saat ini ada %s Kalau kamu mau, aku bisa bantu jelaskan mana yang paling pas buat kebutuhanmu.',
            $scope,
            $catalog,
        );
    }

    private function summarizePackageKnowledge(string $content): string
    {
        $trimmed = trim($content);

        if ($trimmed === '') {
            return 'detail paketnya tersedia dan bisa aku jelaskan satu per satu';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $trimmed) ?: [$trimmed];
        $summary = trim((string) ($sentences[0] ?? $trimmed));

        return Str::limit(rtrim($summary, '.'), 120, '');
    }

    private function selectPrimaryGroundedPackageItem(
        Collection $items,
        ?string $eventType,
        ?string $packageInterest,
    ): mixed {
        return $items
            ->sortByDesc(fn ($item): int => $this->groundedPackageItemScore($item, $eventType, $packageInterest))
            ->first();
    }

    private function groundedPackageItemScore(mixed $item, ?string $eventType, ?string $packageInterest): int
    {
        $haystack = mb_strtolower(trim(sprintf('%s %s', (string) ($item->title ?? ''), (string) ($item->content ?? ''))));
        $score = 0;

        if ($eventType !== null && $eventType !== '') {
            $normalizedEventType = mb_strtolower(trim($eventType));

            if ($this->containsStandaloneKeyword($haystack, $normalizedEventType)) {
                $score += 10;
            } elseif (str_contains($haystack, $normalizedEventType)) {
                $score += 2;
            }
        }

        if ($packageInterest !== null && $packageInterest !== '') {
            $normalizedPackageInterest = mb_strtolower(trim($packageInterest));

            if ($this->containsStandaloneKeyword($haystack, $normalizedPackageInterest)) {
                $score += 6;
            } elseif (str_contains($haystack, $normalizedPackageInterest)) {
                $score += 1;
            }
        }

        return $score;
    }

    private function buildStructuredGroundedPackageReply(
        string $scope,
        mixed $item,
        ?string $packageInterest,
    ): ?string {
        $variants = $this->extractPackageVariants((string) ($item->content ?? ''));

        if ($variants === []) {
            return null;
        }

        if ($packageInterest !== null && $packageInterest !== '') {
            $filtered = array_values(array_filter($variants, function (array $variant) use ($packageInterest): bool {
                return str_contains(
                    mb_strtolower($variant['name']),
                    mb_strtolower($packageInterest),
                );
            }));

            if ($filtered !== []) {
                $variants = $filtered;
            }
        }

        $variants = array_slice($variants, 0, 2);

        if ($variants === []) {
            return null;
        }

        $lines = [sprintf(
            'Untuk %s, ada %s pilihan utama:',
            $scope,
            count($variants) === 1 ? '1' : (string) count($variants),
        ), ''];

        foreach ($variants as $index => $variant) {
            $lines[] = ($index + 1) . '. ' . $this->formatPackageVariantForReply($variant);
        }

        $lines[] = '';
        $lines[] = 'Kalau kamu mau, aku bisa bantu jelaskan mana yang paling pas buat kebutuhanmu.';

        return implode("\n", $lines);
    }

    /**
     * @return list<array{name: string, duration: ?string, team: ?string, include: ?string, price: ?string}>
     */
    private function extractPackageVariants(string $content): array
    {
        $variants = [];
        $current = null;

        foreach (preg_split('/\r?\n/u', trim($content)) ?: [] as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^\d+\.\s*(.+)$/u', $line, $matches) === 1) {
                if ($current !== null) {
                    $variants[] = $current;
                }

                $current = [
                    'name' => trim($matches[1]),
                    'duration' => null,
                    'team' => null,
                    'include' => null,
                    'price' => null,
                ];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^-\s*Durasi:\s*(.+)$/iu', $line, $matches) === 1) {
                $current['duration'] = trim($matches[1]);
                continue;
            }

            if (preg_match('/^-\s*Tim:\s*(.+)$/iu', $line, $matches) === 1) {
                $current['team'] = trim($matches[1]);
                continue;
            }

            if (preg_match('/^-\s*Include:\s*(.+)$/iu', $line, $matches) === 1) {
                $current['include'] = trim($matches[1]);
                continue;
            }

            if (preg_match('/^-\s*Harga:\s*(.+)$/iu', $line, $matches) === 1) {
                $current['price'] = trim($matches[1]);
            }
        }

        if ($current !== null) {
            $variants[] = $current;
        }

        return array_values(array_filter($variants, static fn (array $variant): bool => $variant['name'] !== ''));
    }

    /**
     * @param  array{name: string, duration: ?string, team: ?string, include: ?string, price: ?string}  $variant
     */
    private function formatPackageVariantForReply(array $variant): string
    {
        $segments = [$variant['name']];

        if ($variant['team'] !== null) {
            $segments[] = 'dengan ' . $variant['team'];
        }

        if ($variant['duration'] !== null) {
            $segments[] = 'durasi ' . $variant['duration'];
        }

        if ($variant['include'] !== null) {
            $segments[] = 'sudah termasuk ' . Str::limit($variant['include'], 120, '...');
        }

        if ($variant['price'] !== null) {
            $segments[] = 'harga ' . $variant['price'];
        }

        return implode(', ', array_filter($segments, static fn (?string $segment): bool => $segment !== null && $segment !== ''));
    }

    private function isTransientLlmFailure(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'rate limit')
            || str_contains($message, 'temporar')
            || str_contains($message, 'timeout')
            || str_contains($message, 'connection')
            || str_contains($message, 'openai');
    }

    private function decideTurn(
        Lead $lead,
        Conversation $conv,
        Message $message,
        ClassifierOutput $classifier,
        InterpretationResult $interpretation,
    ): FinalTurnDecision {
        $state = $conv->state()->first();

        $context = new SharedConversationContext(
            conversationId: (string) $conv->id,
            activeTopic: $state?->last_answered_topic,
            currentStage: $conv->stageEnum()->value,
            stageGoal: null,
            latestUserAsk: (string) $message->content,
            recentSummary: null,
            filledSlots: is_array($state?->filled_slots) ? $state->filled_slots : [],
            unresolvedQuestions: array_values(is_array($state?->unresolved_questions) ? $state->unresolved_questions : []),
            askedFields: $conv->askedFields(),
            nextExpectedField: $conv->next_expected_field,
            nextBestAction: $state?->next_best_action,
        );

        $leadSnapshot = $this->leadMemoryService->getSnapshot($lead);
        $missingRecommendationFields = $this->conversationStageService->missingRecommendationFields($conv, $leadSnapshot) !== [];

        $businessFlags = [
            'handoff_required' => $classifier->needsHandoff,
            'handoff_reason' => $classifier->handoffReason,
            'negative_sentiment' => $classifier->sentiment === 'negative' && $classifier->confidence >= 0.8,
            'contains_direct_pricelist_keywords' => $this->containsDirectPricelistKeywords((string) $message->content),
            'pricelist_document_follow_up' => $this->isPricelistDocumentFollowUp($message, $conv),
            'affirming_recent_pricelist_offer' => $this->isAffirmingRecentPricelistOffer($message, $conv),
            'prefers_text_pricing_explanation' => $this->prefersTextPricingExplanation($message, $conv),
            'can_auto_send_pricelist' => $this->canAutoSendPricelistForStage($conv),
            'direct_package_inquiry' => $this->detectDirectPackageSignal($message),
            'short_package_continuation' => $this->isShortPackageContinuation($message, $conv),
            'can_send_grounded_package' => $this->canGroundPackageAnswerForStage($conv),
            'grounded_package_items_available' => $this->resolveGroundedPackageItems($lead, $conv)->isNotEmpty(),
            'missing_recommendation_fields' => $missingRecommendationFields,
            'booking_field_reply_candidate' => $this->isBookingFieldReplyCandidate($lead, $classifier, $message),
        ];

        $input = new TurnDecisionInput(
            turnId: (string) ($this->currentTraceId() ?? $message->id),
            conversationId: (string) $conv->id,
            leadId: (string) $lead->id,
            context: $context,
            ruleInterpretation: $interpretation,
            classifierResult: $classifier,
            currentStage: $conv->stageEnum(),
            businessFlags: $businessFlags,
        );

        return $this->turnDecisionService->decide($input);
    }

    private function logNoReplyExit(
        Lead $lead,
        Conversation $conv,
        Message $message,
        string $reason,
        array $context = [],
        TurnOutcomeType $outcomeType = TurnOutcomeType::NoReply,
    ): void {
        $payload = array_merge([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'conversation_id' => $conv->id,
            'message_id' => $message->id,
            'stage' => $conv->stageEnum()->value,
            'outcome' => $outcomeType->value,
            'reason' => $reason,
        ], $context);

        Log::warning(sprintf('[AgentOrchestrator] No reply exit: %s', $reason), $payload);
        AgentLog::warning('turn.no_reply_exit', $payload);
    }

    private function createQualityFilterHandoffIfNeeded(
        Lead $lead,
        Conversation $conv,
        string $detail,
        string $summaryForAdmin = '',
    ): void {
        $alreadyPending = $conv->handoffRequests()
            ->pending()
            ->exists();

        if ($alreadyPending) {
            return;
        }

        $this->handoffRequestService->create(
            $lead,
            $conv,
            HandoffReason::Other,
            $detail,
            $summaryForAdmin !== '' ? $summaryForAdmin : null,
        );
    }

    private function retryEmptyResponse(
        Lead $lead,
        Conversation $conv,
        string $intent,
        Message $message,
        ?InterpretationResult $interpretation = null,
    ): string
    {
        try {
            $responseText = $this->runResponse($lead, $conv, $intent);

            if ($responseText !== '') {
                Log::info('[AgentOrchestrator] Empty response retry succeeded', [
                    'tenant_id' => $lead->tenant_id,
                    'lead_id' => $lead->id,
                    'conversation_id' => $conv->id,
                    'intent' => $intent,
                    'message_id' => $message->id,
                ]);

                return $responseText;
            }
        } catch (Throwable $e) {
            Log::error('[AgentOrchestrator] Empty response retry failed', [
                'tenant_id' => $lead->tenant_id,
                'lead_id' => $lead->id,
                'conversation_id' => $conv->id,
                'intent' => $intent,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::warning('[AgentOrchestrator] Empty response generated, using fallback reply', [
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'conversation_id' => $conv->id,
            'intent' => $intent,
            'message_id' => $message->id,
        ]);

        $fallback = $this->contextAwareFallbackBuilder->build(
            $lead,
            $conv,
            $message,
            $interpretation,
            null,
            'empty_response_context_fallback',
        );

        return $fallback['message'];
    }

    private function isGreetingMessage(string $content): bool
    {
        if ($content === '') {
            return false;
        }

        return str_contains($content, 'halo')
            || str_contains($content, 'hai')
            || str_contains($content, 'hi')
            || str_contains($content, 'pagi')
            || str_contains($content, 'siang')
            || str_contains($content, 'sore')
            || str_contains($content, 'malam');
    }

    private function isDiscountInquiry(string $content): bool
    {
        return str_contains($content, 'diskon')
            || str_contains($content, 'promo')
            || str_contains($content, 'potongan harga');
    }

    private function isTestMessage(string $content): bool
    {
        return $content === 'tes'
            || $content === 'test'
            || $content === 'testing'
            || $content === 'cek'
            || $content === 'check';
    }

    private function containsPricingKeywords(string $content): bool
    {
        $content = strtolower(trim($content));

        if ($content === '') {
            return false;
        }

        return str_contains($content, 'pricelist')
            || str_contains($content, 'price list')
            || str_contains($content, 'daftar harga')
            || str_contains($content, 'harga paket')
            || str_contains($content, 'berapa harga')
            || str_contains($content, 'harga nya')
            || str_contains($content, 'harganya')
            || str_contains($content, 'paket wedding')
            || str_contains($content, 'paket foto')
            || str_contains($content, 'paket video')
            || str_contains($content, 'minta paket')
            || str_contains($content, 'minta price')
            || str_contains($content, 'minta pricelist');
    }

    private function containsDirectPricelistKeywords(string $content): bool
    {
        $content = strtolower(trim($content));

        if ($content === '') {
            return false;
        }

        return str_contains($content, 'pricelist')
            || str_contains($content, 'price list')
            || str_contains($content, 'daftar harga')
            || str_contains($content, 'minta price')
            || str_contains($content, 'minta pricelist');
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    private function isPricelistDocumentFollowUp(Message $message, Conversation $conversation): bool
    {
        $content = strtolower(trim((string) $message->content));
        $quotedContent = strtolower(trim((string) ($message->quoted_content ?? '')));

        if (! $this->containsDocumentRequestKeywords($content)) {
            return false;
        }

        if ($quotedContent !== '' && $this->containsPricingKeywords($quotedContent)) {
            return true;
        }

        $state = $conversation->state()->first();
        if (! $state) {
            return false;
        }

        $currentIntent = strtolower(trim((string) ($state->current_intent ?? '')));
        $lastAnsweredTopic = strtolower(trim((string) ($state->last_answered_topic ?? '')));
        $nextBestAction = strtolower(trim((string) ($state->next_best_action ?? '')));
        $lastToolResultSummary = strtolower(trim((string) ($state->last_tool_result_summary ?? '')));
        $lastAgentMessage = strtolower(trim((string) ($state->last_agent_message ?? '')));

        if (in_array($currentIntent, ['price_inquiry', 'package_inquiry'], true)) {
            return true;
        }

        if ($lastAnsweredTopic === 'pricing' || $nextBestAction === 'share_pricelist') {
            return true;
        }

        if (str_contains($lastToolResultSummary, 'pricelist')) {
            return true;
        }

        return $lastAgentMessage !== ''
            && ($this->containsPricingKeywords($lastAgentMessage)
                || str_contains($lastAgentMessage, 'harga dulu')
                || str_contains($lastAgentMessage, 'isi paket dulu'));
    }

    private function containsDocumentRequestKeywords(string $content): bool
    {
        return str_contains($content, 'pdf')
            || str_contains($content, 'file')
            || str_contains($content, 'dokumen')
            || str_contains($content, 'document')
            || str_contains($content, 'lampiran')
            || str_contains($content, 'brosur')
            || str_contains($content, 'katalog');
    }

    private function prefersTextPricingExplanation(Message $message, ?Conversation $conversation = null): bool
    {
        $content = mb_strtolower(trim((string) $message->content));
        $quotedContent = mb_strtolower(trim((string) ($message->quoted_content ?? '')));

        if ($content === '') {
            return false;
        }

        $cannotOpenDocument = $this->containsAnyFragment($content, [
            'ga bisa buka pdf',
            'gak bisa buka pdf',
            'gabisa buka pdf',
            'ngga bisa buka pdf',
            'nggak bisa buka pdf',
            'ga bisa buka file',
            'gak bisa buka file',
            'gabisa buka file',
            'ga bisa buka dokumen',
            'gak bisa buka dokumen',
            'gabisa buka dokumen',
            'hp aku gabisa buka pdf',
            'hp saya gabisa buka pdf',
            'tidak bisa buka pdf',
            'nggak kebuka pdf',
            'ga kebuka pdf',
        ]);

        $asksTextExplanation = $this->containsAnyFragment($content, [
            'jelasin aja',
            'jelaskan aja',
            'jelasin di chat',
            'jelaskan di chat',
            'chat aja',
            'ketik aja',
            'tulis aja',
            'tanpa pdf',
            'tanpa file',
            'gak usah pdf',
            'ga usah pdf',
            'nggak usah pdf',
            'tidak usah pdf',
        ]);

        if ($cannotOpenDocument || $asksTextExplanation) {
            return true;
        }

        if ($conversation === null) {
            return false;
        }

        $state = $conversation->state()->first();
        $lastAgentMessage = mb_strtolower(trim((string) ($state?->last_agent_message ?? '')));
        $lastToolResultSummary = mb_strtolower(trim((string) ($state?->last_tool_result_summary ?? '')));

        if (
            $quotedContent !== ''
            && $this->containsPricingKeywords($quotedContent)
            && $this->containsAnyFragment($content, ['jelasin', 'jelaskan', 'chat aja', 'ketik aja', 'tulis aja'])
        ) {
            return true;
        }

        return $this->containsAnyFragment($lastAgentMessage, ['pricelist', 'pdf', 'daftar harga'])
            && str_contains($lastToolResultSummary, 'pricelist')
            && $this->containsAnyFragment($content, ['jelasin', 'jelaskan', 'chat aja', 'ketik aja', 'tulis aja']);
    }

    private function isAffirmingRecentPricelistOffer(Message $message, Conversation $conversation): bool
    {
        $content = mb_strtolower(trim((string) $message->content));

        if (! $this->isAmbiguousShortConfirmation($content)) {
            return false;
        }

        $state = $conversation->state()->first();
        if ($state === null) {
            return false;
        }

        $lastAgentMessage = mb_strtolower(trim((string) ($state->last_agent_message ?? '')));
        $lastAgentQuestion = mb_strtolower(trim((string) ($state->last_agent_question ?? '')));
        $lastToolResultSummary = mb_strtolower(trim((string) ($state->last_tool_result_summary ?? '')));
        $nextBestAction = mb_strtolower(trim((string) ($state->next_best_action ?? '')));

        return $this->containsAnyFragment($lastAgentMessage . "\n" . $lastAgentQuestion, [
            'pricelist',
            'price list',
            'daftar harga',
            'pdf',
            'brosur',
            'katalog',
        ]) || $nextBestAction === 'share_pricelist'
            || str_contains($lastToolResultSummary, 'pricelist');
    }

    private function continueInterpretationFromHistory(
        Message $message,
        Conversation $conversation,
        InterpretationResult $interpretation,
    ): InterpretationResult {
        if ($interpretation->hasClearIntent()) {
            return $interpretation;
        }

        if (! $this->isShortPackageContinuation($message, $conversation)) {
            return $interpretation;
        }

        $state = $conversation->state()->first();
        $filledSlots = is_array($state?->filled_slots) ? $state->filled_slots : [];
        $slots = $interpretation->slots;

        if (! isset($slots['event_type']) && is_scalar($filledSlots['event_type'] ?? null)) {
            $slots['event_type'] = trim((string) $filledSlots['event_type']);
        }

        if (! isset($slots['package_interest']) && is_scalar($filledSlots['package_interest'] ?? null)) {
            $slots['package_interest'] = trim((string) $filledSlots['package_interest']);
        }

        return new InterpretationResult(
            canonicalIntent: 'package_inquiry',
            legacyIntent: 'tanya_paket',
            slots: array_filter($slots, static fn (mixed $value): bool => $value !== null && $value !== ''),
            confidence: max($interpretation->confidence, 0.84),
            source: 'history+rules',
        );
    }

    private function isShortPackageContinuation(Message $message, Conversation $conversation): bool
    {
        $content = mb_strtolower(trim((string) $message->content));

        if ($content === '' || $this->containsDirectPricelistKeywords($content)) {
            return false;
        }

        if (! $this->isShortContinuationCandidate($content)) {
            return false;
        }

        $state = $conversation->state()->first();
        if ($state === null) {
            return false;
        }

        $filledSlots = is_array($state->filled_slots) ? $state->filled_slots : [];
        $eventType = $this->stringOrNull($filledSlots['event_type'] ?? null);
        $packageInterest = $this->stringOrNull($filledSlots['package_interest'] ?? null);

        if (! $this->conversationHasRecentPackageContext($conversation, $state)) {
            return false;
        }

        return $this->containsAnyFragment($content, [
            'jelaskan',
            'detail',
            'yang wedding',
            'wedding ka',
            'photo dan video',
            'photo video',
            'photo + video',
            'paketnya',
            'yang mana',
        ]) || ($eventType !== null && str_contains($content, mb_strtolower($eventType)))
            || ($packageInterest !== null && str_contains($content, mb_strtolower($packageInterest)));
    }

    private function isShortContinuationCandidate(string $content): bool
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $content) ?? $content);
        $wordCount = count(array_values(array_filter(preg_split('/\s+/u', $normalized) ?: [])));

        return $wordCount <= 6 || mb_strlen($normalized) <= 40;
    }

    private function conversationHasRecentPackageContext(Conversation $conversation, mixed $state): bool
    {
        $currentIntent = mb_strtolower(trim((string) ($state->current_intent ?? '')));
        $lastAnsweredTopic = mb_strtolower(trim((string) ($state->last_answered_topic ?? '')));
        $lastAgentMessage = mb_strtolower(trim((string) ($state->last_agent_message ?? '')));

        if (in_array($currentIntent, ['package_inquiry', 'price_inquiry'], true)) {
            return true;
        }

        if ($lastAnsweredTopic === 'pricing') {
            return true;
        }

        if ($this->containsAnyFragment($lastAgentMessage, ['paket', 'photo + album', 'photo + video', 'isi paket'])) {
            return true;
        }

        $recentHistory = $conversation->messages()
            ->latest('id')
            ->limit(4)
            ->get(['content'])
            ->pluck('content')
            ->map(fn (?string $content): string => mb_strtolower(trim((string) $content)))
            ->implode("\n");

        return $this->containsAnyFragment($recentHistory, ['paket', 'photo + album', 'photo + video', 'isi paket', 'wedding']);
    }

    /**
     * @param  list<string>  $fragments
     */
    private function containsAnyFragment(string $content, array $fragments): bool
    {
        foreach ($fragments as $fragment) {
            if (str_contains($content, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   classifier: ClassifierOutput,
     *   raw_analyzer_intent: string,
     *   rule_intent: ?string,
     *   final_intent: string,
     *   override_reason: ?string,
     *   override_rejected_reason: ?string
     * }
     */
    private function applyIntentGuards(
        ClassifierOutput $classifier,
        Message $message,
        Conversation $conversation,
        string $rawAnalyzerIntent,
        ?string $ruleIntent,
        ?string $overrideReason,
    ): array {
        $content = mb_strtolower(trim((string) $message->content));
        $state = $conversation->state()->first();
        $lastAgentMessage = mb_strtolower(trim((string) ($state?->last_agent_message ?? '')));
        $lastAgentQuestion = mb_strtolower(trim((string) ($state?->last_agent_question ?? '')));
        $lastAgentContext = trim($lastAgentQuestion !== '' ? $lastAgentQuestion : $lastAgentMessage);

        if ($this->isQuestioningPreviousBookingStep($content, $lastAgentContext)) {
            $classifier = $this->withResolvedIntent($classifier, 'complaint', ConversationStage::ObjectionHandling);
            $overrideReason = 'guard:user_questioned_previous_booking_step';
        } elseif (
            $classifier->intent === 'payment_inquiry'
            && ! $this->hasExplicitPaymentLexicon($content)
        ) {
            if ($this->containsAnyFragment($content, ['harga', 'pricelist', 'price list', 'biaya', 'berapa'])) {
                $classifier = $this->withResolvedIntent($classifier, 'tanya_harga', ConversationStage::PackageRecommendation);
                $overrideReason = 'guard:payment_requires_explicit_lexicon';
            } elseif ($this->containsAnyFragment($content, ['paket', 'package', 'layanan', 'isi paket', 'apa aja', 'dapat apa', 'detail'])) {
                $classifier = $this->withResolvedIntent($classifier, 'tanya_paket', ConversationStage::PackageRecommendation);
                $overrideReason = 'guard:payment_requires_explicit_lexicon';
            }
        } elseif (
            $classifier->intent === 'ready_to_book'
            && $this->isAmbiguousShortConfirmation($content)
            && $this->lastAgentOfferedDetailExplanation($lastAgentContext)
        ) {
            $classifier = $this->withResolvedIntent($classifier, 'tanya_paket', ConversationStage::PackageRecommendation);
            $overrideReason = 'guard:short_confirmation_matches_detail_offer';
        } elseif (
            $classifier->intent === 'ready_to_book'
            && $this->isAmbiguousShortConfirmation($content)
            && ! $this->lastAgentOfferedBookingContinuation($lastAgentContext)
        ) {
            $classifier = $this->withResolvedIntent($classifier, 'tanya_paket', ConversationStage::PackageRecommendation);
            $overrideReason = 'guard:short_confirmation_without_booking_offer';
        }

        return [
            'classifier' => $classifier,
            'raw_analyzer_intent' => $rawAnalyzerIntent,
            'rule_intent' => $ruleIntent,
            'final_intent' => $classifier->intent,
            'override_reason' => $overrideReason,
            'override_rejected_reason' => $overrideReason !== null && str_starts_with($overrideReason, 'guard:')
                ? $overrideReason
                : null,
        ];
    }

    private function withResolvedIntent(
        ClassifierOutput $classifier,
        string $intent,
        ?ConversationStage $suggestedNextStage = null,
    ): ClassifierOutput {
        $needsHandoff = in_array($intent, ['availability', 'custom_package', 'payment_proof', 'opt_out'], true);

        return new ClassifierOutput(
            intent: $intent,
            sentiment: $classifier->sentiment,
            extractedFields: $classifier->extractedFields,
            needsHandoff: $needsHandoff,
            handoffReason: $needsHandoff ? $intent : null,
            confidence: $classifier->confidence,
            currentStage: $classifier->currentStage,
            suggestedNextStage: $suggestedNextStage ?? $classifier->suggestedNextStage,
            missingCriticalFields: $classifier->missingCriticalFields,
        );
    }

    /**
     * @return array{message: string, topic_changed: bool, user_latest_topic: string, topic_change_reason: ?string}
     */
    private function validateReplyTopicBeforeSend(
        string $messageContent,
        ?ClassifierOutput $classifier,
        ?InterpretationResult $interpretation,
        string $rawControllerReply,
        string $finalReply,
    ): array {
        $userLatestTopic = $this->detectUserLatestTopic($messageContent, $classifier, $interpretation);

        if (
            $userLatestTopic !== 'other'
            && $this->replyMatchesTopic($rawControllerReply, $userLatestTopic)
            && ! $this->replyMatchesTopic($finalReply, $userLatestTopic)
        ) {
            return [
                'message' => $rawControllerReply,
                'topic_changed' => true,
                'user_latest_topic' => $userLatestTopic,
                'topic_change_reason' => 'pre_send_topic_mismatch_reverted_to_raw_controller_reply',
            ];
        }

        return [
            'message' => $finalReply,
            'topic_changed' => false,
            'user_latest_topic' => $userLatestTopic,
            'topic_change_reason' => null,
        ];
    }

    private function detectUserLatestTopic(
        string $messageContent,
        ?ClassifierOutput $classifier,
        ?InterpretationResult $interpretation,
    ): string {
        $content = mb_strtolower(trim($messageContent));

        if ($this->hasExplicitPaymentLexicon($content)) {
            return 'payment';
        }

        if ($this->containsAnyFragment($content, ['harga', 'pricelist', 'price list', 'biaya', 'berapa'])) {
            return 'price';
        }

        if ($this->containsAnyFragment($content, ['paket', 'package', 'layanan', 'isi paket', 'apa aja', 'dapat apa', 'detail'])) {
            return 'package';
        }

        $intent = $classifier?->intent ?? $interpretation?->legacyIntent ?? '';

        return match ($intent) {
            'payment_inquiry', 'payment_proof' => 'payment',
            'ready_to_book' => 'booking',
            'tanya_harga' => 'price',
            'tanya_paket', 'bandingkan_paket' => 'package',
            default => 'other',
        };
    }

    private function replyMatchesTopic(string $reply, string $topic): bool
    {
        $content = mb_strtolower(trim($reply));

        if ($content === '') {
            return false;
        }

        return match ($topic) {
            'payment' => $this->hasExplicitPaymentLexicon($content) || $this->containsAnyFragment($content, ['pembayaran', 'rekening', 'bank']),
            'booking' => $this->containsAnyFragment($content, ['booking', 'konfirmasi', 'form', 'data acara', 'data booking']),
            'price' => $this->containsAnyFragment($content, ['harga', 'rp', 'idr', 'juta', 'ribu', 'pricelist', 'biaya']),
            'package' => $this->containsAnyFragment($content, ['paket', 'isi paket', 'termasuk', 'include', 'foto', 'video', 'album', 'dokumentasi', 'crew', 'jam']),
            default => true,
        };
    }

    private function hasExplicitPaymentLexicon(string $content): bool
    {
        if ($content === '') {
            return false;
        }

        return preg_match('/\b(dp|bayar|payment|pelunasan|transfer|invoice)\b/u', $content) === 1
            || str_contains($content, 'booking lanjut')
            || str_contains($content, 'jadi booking')
            || str_contains($content, 'lanjut booking');
    }

    private function isAmbiguousShortConfirmation(string $content): bool
    {
        return preg_match('/^(boleh|boleh ka|boleh kak|iya|iya ka|iya kak|oke|ok|sip|gas|lanjut|lanjut ka|lanjut kak)$/u', $content) === 1;
    }

    private function lastAgentOfferedDetailExplanation(string $lastAgentContext): bool
    {
        return $lastAgentContext !== ''
            && $this->containsAnyFragment($lastAgentContext, [
                'jelaskan detail',
                'jelasin detail',
                'detail paket',
                'lebih lanjut',
                'aku bisa bantu jelaskan',
                'aku bisa bantu jelasin',
                'mau aku jelaskan',
            ]);
    }

    private function lastAgentOfferedBookingContinuation(string $lastAgentContext): bool
    {
        return $lastAgentContext !== ''
            && $this->containsAnyFragment($lastAgentContext, [
                'lanjut ke booking',
                'lanjut booking',
                'proses booking',
                'form booking',
                'data booking',
                'lanjut admin',
                'kirim data booking',
            ]);
    }

    private function isQuestioningPreviousBookingStep(string $content, string $lastAgentContext): bool
    {
        if ($content === '') {
            return false;
        }

        if ($this->containsAnyFragment($content, [
            'kenapa langsung ke booking',
            'kenapa langsung booking',
            'kok langsung ke booking',
            'katanya mau kamu jelaskan',
            'katanya mau kamu jelasin',
            'katanya mau jelaskan',
            'katanya mau jelasin',
            'bukannya mau jelasin',
            'langsung ke booking',
        ])) {
            return true;
        }

        return str_contains($content, 'booking')
            && $this->containsAnyFragment($content, ['kenapa', 'kok', 'katanya', 'bukannya'])
            && $this->containsAnyFragment($lastAgentContext, ['jelaskan', 'jelasin', 'detail paket', 'lebih lanjut']);
    }

    private function containsStandaloneKeyword(string $haystack, string $keyword): bool
    {
        if ($haystack === '' || $keyword === '') {
            return false;
        }

        $pattern = '/(?<!\pL)' . preg_quote(mb_strtolower($keyword), '/') . '(?!\pL)/u';

        return preg_match($pattern, mb_strtolower($haystack)) === 1;
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
