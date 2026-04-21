# Phase 9 — Baileys Hardening

Audit and harden the WhatsApp integration layer against unofficial-library risks.

## Objective
Reduce duplicate processing, double replies, and instability caused by Baileys reconnect/retry behavior.

## Tasks
1. Inspect how incoming messages are identified and persisted.
2. Check whether one WhatsApp message can be processed more than once.
3. Add or improve:
   - idempotency key handling
   - processed-message recording
   - conversation locking
   - retry safety
   - reconnect safety
4. Verify quoted-message context handling.
5. Check whether outgoing sends can duplicate after retries or reconnect.
6. Add human-takeover or AI-pause support if practical.

## Laravel Suggestions
Possible classes:
- DuplicateMessageGuardService
- ConversationLockService
- OutgoingMessageDispatchService
- HumanTakeoverService

## Deliverables
Provide:
- Baileys risk findings
- hardening changes
- files changed
- unresolved risk notes

## Constraints
- Keep implementation production-minded
- Do not assume official WhatsApp guarantees exist here
