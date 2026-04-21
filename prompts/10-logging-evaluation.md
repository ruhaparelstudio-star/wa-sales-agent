# Phase 10 — Logging and Evaluation

Add or improve observability for the AI sales agent.

## Objective
Make the system easier to debug, measure, and improve.

## Minimum Logging
Log at least:
- incoming message id
- normalized message summary
- detected intent
- extracted slots
- current stage
- stage transition
- fallback usage
- LLM call metadata
- response type
- tool/action usage
- duplicate-prevention decisions

## Evaluation Goals
Add a lightweight evaluator or score for each response:
- did it answer the latest user question?
- did it repeat unnecessary questions?
- was it aligned with stage?
- did it include CTA when needed?
- did it avoid false promises?

## Laravel Suggestions
Possible classes or tables:
- conversation_evaluations
- llm_call_logs
- stage_transition_logs
- fallback_logs

## Deliverables
Provide:
- logging plan
- evaluator design
- files changed
- examples of useful logs

## Constraints
- Logs should be actionable, not noisy
- Make debugging easier for future iterations
