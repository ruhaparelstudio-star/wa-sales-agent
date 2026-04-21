# Phase 1 — Full Repository Audit

Audit the current Laravel repository and map the real system flow for this AI WhatsApp Sales Agent.

## Objective
Understand the actual architecture before changing anything.

## Tasks
1. Locate the main entry points that receive or process Baileys-originated WhatsApp messages.
2. Trace the real flow from:
   - incoming message or webhook
   - normalization
   - conversation lookup
   - prompt/context assembly
   - LLM call
   - outgoing send
3. Identify the Laravel files/classes responsible for:
   - message ingestion
   - queue dispatching
   - AI orchestration
   - prompt building
   - memory/state loading
   - message persistence
   - outgoing send
   - retry or reconnect handling
4. Detect likely causes of:
   - repeated questions
   - context loss
   - generic fallback replies
   - lack of closing behavior
   - duplicate replies

## Deliverables
Provide:
- text architecture map
- list of critical classes/files
- list of weak points with severity
- likely root causes for the current behavior

## Constraints
- Do not change code yet
- Do not invent missing modules
- Base all findings on actual repository evidence
