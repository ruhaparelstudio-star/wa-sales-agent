# Phase 8 — Fallback Guard

Audit and fix fallback behavior so the system stops resetting the conversation.

## Objective
Prevent context-breaking generic fallback replies.

## Harmful Examples
These should not be used when user intent is already clear:
- "Ada yang bisa saya bantu?"
- "Mau tanya apa?"
- "Yang paling ingin kamu cari tahu apa?"

## Tasks
1. Find all fallback logic and fallback strings.
2. Identify where generic fallback overrides real context.
3. Replace with context-aware fallback rules using:
   - current intent
   - current stage
   - actually missing slots only if necessary
4. Ensure payment or booking questions never receive generic fallback.

## Laravel Suggestions
Possible classes:
- FallbackGuardService
- ContextAwareFallbackBuilder

## Deliverables
Provide:
- fallback inventory
- new fallback rules
- files changed
- examples of corrected fallback behavior

## Constraints
- Fallback should only clarify what is truly unclear
- Fallback must not reset the conversation stage
