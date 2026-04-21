# Target Laravel Service Map

This is the preferred logical breakdown for the AI sales agent if the current repo needs clearer separation.

## Core Services
- MessageNormalizationService
- ConversationStateService
- IntentExtractionService
- SlotExtractionService
- ConversationInterpretationService
- SalesStageService
- LeadTemperatureResolver
- ClosingPolicyService
- CtaSuggestionService
- ResponseGenerationService
- FallbackGuardService
- DuplicateMessageGuardService
- ConversationLockService
- HumanTakeoverService
- ConversationEvaluatorService

## Data / Logging
- conversations
- messages
- conversation_states
- stage_transition_logs
- llm_call_logs
- fallback_logs
- conversation_evaluations

## Suggested Flow
Incoming message
-> normalize
-> deduplicate
-> load conversation
-> load state
-> interpret intent + slots
-> update state
-> resolve stage
-> resolve CTA / action
-> generate response
-> fallback guard
-> send
-> log evaluation
