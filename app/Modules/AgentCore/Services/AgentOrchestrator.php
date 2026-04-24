<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\Contracts\LlmClientInterface;
use App\Modules\AgentCore\Contracts\TurnDecisionServiceInterface;
use App\Modules\AgentCore\DTOs\ClassifierOutput;
use App\Modules\Conversations\Actions\RecordAskedFieldAction;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Services\ConversationStageService;
use App\Modules\Conversations\Services\ConversationStateService;
use App\Modules\Conversations\Services\ConversationSummaryService;
use App\Modules\Conversations\Services\HandoffRequestService;
use App\Modules\Knowledge\Services\KnowledgeRetrievalService;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadMemoryService;
use App\Modules\Leads\Services\LeadService;
use App\Modules\Leads\Services\LeadStageService;
use App\Modules\WhatsApp\Models\WhatsAppAgent;
use App\Modules\WhatsApp\Services\OutboundDispatchService;
use App\Modules\AgentCore\Dispatch\ActionDispatcher;
use App\Modules\Conversations\Models\Message;

class AgentOrchestrator
{
    private readonly TurnPipelineService $turnPipelineService;

    private readonly TurnLifecycleService $turnLifecycleService;

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
    ) {
        $this->turnPipelineService = new TurnPipelineService(
            $this->llm,
            $this->contextAssembler,
            $this->guardrailService,
            $this->humanizerService,
            $this->qualityFilterService,
            $this->riskPolicyService,
            $this->delayPolicyService,
            $this->leadService,
            $this->leadMemoryService,
            $this->leadStageService,
            $this->handoffRequestService,
            $this->outboundDispatchService,
            $this->conversationSummaryService,
            $this->knowledgeRetrievalService,
            $this->conversationStageService,
            $this->recordAskedFieldAction,
            $this->conversationStateService,
            $this->conversationInterpretationService,
            $this->contextAwareFallbackBuilder,
            $this->fallbackGuardService,
            $this->turnDecisionService,
            $this->actionDispatcher,
        );

        $this->turnLifecycleService = new TurnLifecycleService(
            $this->turnPipelineService,
            $this->responseEvaluatorService,
        );
    }

    public function handleInbound(Message $message, Lead $lead, Conversation $conv, ?string $traceId = null): void
    {
        $this->turnLifecycleService->handleInbound($message, $lead, $conv, $traceId);
    }

    public function runClassifier(Lead $lead, Conversation $conv): ClassifierOutput
    {
        return $this->turnPipelineService->runClassifier($lead, $conv);
    }

    public function runResponse(Lead $lead, Conversation $conv, string $intent): string
    {
        return $this->turnPipelineService->runResponse($lead, $conv, $intent);
    }

    public function runFollowUp(Lead $lead, Conversation $conv): string
    {
        return $this->turnPipelineService->runFollowUp($lead, $conv);
    }

    public function generateFollowUp(Lead $lead): ?string
    {
        return $this->turnPipelineService->generateFollowUp($lead);
    }

    public function generateSummary(Conversation $conv): void
    {
        $this->turnPipelineService->generateSummary($conv);
    }

    public function pipeline(): TurnPipelineService
    {
        return $this->turnPipelineService;
    }
}
