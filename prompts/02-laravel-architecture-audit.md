# Phase 2 — Laravel Architecture Audit

Audit whether the current Laravel structure is suitable for a stateful AI sales agent.

## Objective
Evaluate code organization and identify where to place the fixes.

## Tasks
1. Inspect whether the codebase already separates concerns across:
   - controllers
   - services
   - jobs
   - actions
   - events/listeners
   - repositories/query services
   - models
2. Identify where the AI logic currently lives:
   - controller?
   - job?
   - service?
   - helper?
   - single monolithic class?
3. Assess whether the current structure supports:
   - conversation state
   - intent extraction
   - stage transitions
   - follow-ups
   - duplicate prevention
   - logging/evaluation
4. Recommend a Laravel-friendly module layout for the sales agent.

## Preferred Direction
Recommend explicit modules or services such as:
- MessageNormalizationService
- ConversationStateService
- IntentExtractionService
- SlotExtractionService
- SalesStageService
- ClosingPolicyService
- ResponseGenerationService
- DuplicateMessageGuardService
- ConversationEvaluatorService

## Deliverables
Provide:
- current architectural assessment
- recommended Laravel service map
- target folder/class structure
- migration risk notes

## Constraints
- Adapt to existing repo conventions
- Do not over-modularize if the codebase is still small
