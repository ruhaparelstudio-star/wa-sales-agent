# Phase 11 — Test Suite

Create or improve tests for the exact business problems happening in this AI sales agent.

## Objective
Protect against regression in repetition, context, closing, and duplicate-message handling.

## Required Scenarios
At minimum add tests for:
1. User asks about booking payment -> agent answers payment, not generic fallback
2. User already provided event time -> agent does not ask for time again
3. User shows buying signal -> agent moves into closing behavior
4. Duplicate incoming event -> agent does not send double reply
5. Missing tool/action capability -> agent does not make fake promises
6. Stage after payment inquiry does not regress into broad discovery
7. Fallback logic does not trigger when intent is clear

## Laravel Suggestions
Prefer:
- feature tests for message-processing flow
- service tests for interpretation/stage logic
- unit tests for duplicate guards and fallback guards

## Deliverables
Provide:
- test plan
- files changed
- test strategy
- coverage gaps that remain

## Constraints
- Favor realistic conversation-flow tests
- Cover both logic and integration points where practical
