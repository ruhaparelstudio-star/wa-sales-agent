# Phase 1 Turn Decision Rules

## Goal

`TurnDecisionService` is now the final authority for one turn. Rule extraction, classifier output, guard signals, stage consistency, fallback eligibility, and business flags all flow into one `FinalTurnDecision`.

## Input sources

- Rule interpretation provides the rule-side intent and slot signals.
- Classifier output provides the LLM-side intent, extracted fields, handoff flags, and confidence.
- Shared conversation context carries current stage, active topic, unresolved questions, asked fields, and latest user ask.
- Structured state carries the durable state snapshot already stored on the conversation.
- Business flags carry guard overrides, handoff/no-reply requirements, negative-sentiment escalation, and forced fallback reasons.

## Final intent selection

1. Explicit guard override wins if present.
2. If rule and classifier conflict, the service records a conflict.
3. On conflict, the service prefers the stage-consistent intent when one side matches the current stage better.
4. If the classifier is unclear (`other`) but rules are clear, the rule intent wins.
5. Otherwise the classifier stays in control.

## Final action selection

- `do_not_reply` if business flags explicitly require no reply.
- `request_human_handoff` for negative sentiment, explicit handoff requirement, or handoff-class intents such as `payment_proof`, `availability`, `custom_package`, and `opt_out`.
- `reply_with_fallback` when the turn is fallback-eligible and the service is told to force a safe fallback or the final intent remains unclear.
- `guide_to_booking` for `ready_to_book`.
- `reply_with_price_details`, `reply_with_package_details`, or `reply_with_package_comparison` for pricing/package flows.
- `respond_to_user` for everything else that should continue through the normal responder path.

## Stage handling

- The decision contract predicts `stage_after` first.
- `AgentOrchestrator` then passes the final intent through the existing `ConversationStageService`.
- If the stage engine applies a different effective stage, the final decision notes are updated so the difference is visible in logs.

## Logging

- `DecisionTrace` logs the full normalized `FinalTurnDecision`.
- Conflicts, notes, chosen action, and effective stage are all emitted in one trace payload.

## Integration boundary

- `AgentOrchestrator` still owns branch execution.
- It no longer decides intent/action/stage ad hoc.
- Downstream branches read `final_decision.action`, `final_decision.intent`, and `final_decision.stage_after` from the single decision contract.
