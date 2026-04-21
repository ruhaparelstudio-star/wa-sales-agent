# Phase 7 — Closing Engine

Implement or improve closing logic for warm and hot leads.

## Objective
Make the agent capable of naturally moving leads toward booking.

## Strong Buying Signals
Treat these as strong signals:
- payment questions
- DP questions
- booking procedure questions
- lock date questions
- user confirms interest in a package
- user has already provided enough event details

## Tasks
1. Inspect the current system for any closing behavior.
2. Add a policy layer for:
   - soft CTA
   - medium CTA
   - hard CTA
3. Ensure payment-related questions receive:
   - direct answer
   - clear next step
   - suitable CTA
4. Ensure the system does not remain stuck in pure information mode when the user is ready.

## Laravel Suggestions
Possible classes:
- ClosingPolicyService
- CtaSuggestionService
- LeadReadinessScorer

## Deliverables
Provide:
- closing rules
- CTA policy by stage and lead temperature
- files changed
- examples of closing responses

## Constraints
- Do not make the bot too aggressive
- Match pressure level to stage and lead readiness
