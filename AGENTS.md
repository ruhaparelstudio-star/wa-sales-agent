# AGENTS.md
saya sudah menggunakan docker.
You are working inside a Laravel backend that powers an AI WhatsApp Sales Agent built on top of Baileys.

## Main Business Goal
Improve the system so it:
- does not repeat questions already answered by the user
- maintains multi-turn conversation context
- tracks sales stages explicitly
- performs soft and hard closing appropriately
- avoids false promises and generic fallback replies
- is stable despite Baileys duplicate/reconnect behavior
- is easier to observe, debug, and test

## Delivery Style
- Work incrementally and safely
- Inspect the real repository before making decisions
- Prefer extending/refactoring existing Laravel code over rewriting from scratch
- Preserve current working behavior unless it is clearly harmful
- Keep changes readable and traceable
- Explain findings based on actual files, not assumptions

## Architecture Direction
The target system should move toward this shape:

Baileys/Webhook/Event Consumer
-> Message Normalization
-> Conversation Loader
-> Structured State Loader
-> Intent + Slot Extraction
-> Stage Engine / Policy Engine
-> Tool or Action Layer
-> Response Generation Layer
-> Guardrails / Fallback Safety
-> Outgoing Sender
-> Logging / Analytics / Evaluation

## Laravel-Oriented Expectations
Prefer explicit modules/services such as:
- Services for interpretation and state updates
- Jobs for async tasks and follow-ups
- Repositories or query services where complex data access exists
- Events/listeners when useful
- Database tables for state, transitions, and logs
- Tests for conversation flows and duplicate-prevention logic

## Priority Order
1. Conversation state correctness
2. Intent and slot extraction
3. Sales stage engine
4. Prompt structure
5. Closing behavior
6. Fallback safety
7. Baileys duplicate/reconnect safety
8. Logging and evaluation
9. Tests

## Hard Rules
- Always answer the user's latest concrete question first
- Never re-ask for slots that are already filled unless the user corrected them
- Do not reset the conversation with generic questions when intent is clear
- If the user asks about payment, booking, DP, or locking a date, do not go back to broad discovery
- Never claim a tool/action happened if the system does not actually perform it
- Do not generate fake promises like “we will send it soon” unless that workflow exists
- This is a sales agent, not a generic assistant or FAQ bot

## Expected Output for Each Task
Always return:
- Findings
- Problem list
- Proposed changes
- Files changed
- Risks / edge cases
- Tests added or recommended

## Anti-Patterns to Remove
- generic fallback loops
- relying only on raw chat history without structured state
- one giant prompt handling everything
- stage regression after payment or booking intent
- duplicate replies caused by retries or reconnect
- responses that ignore stored state
