# Phase 6 — Prompt Refactor

Refactor prompt orchestration so the agent answers correctly, uses state, and follows sales flow.

## Objective
Improve prompt quality without relying on one giant generic prompt.

## Tasks
1. Find all prompt definitions and prompt assembly logic.
2. Identify whether one generic prompt is doing too much.
3. Separate concerns where appropriate:
   - interpretation prompt
   - response-generation prompt
   - summarization prompt
   - follow-up prompt
   - evaluation prompt
4. Update the response-generation prompt so it:
   - always answers the latest user question first
   - uses structured conversation state
   - avoids repeating filled slots
   - respects current stage
   - avoids fake promises
   - includes CTA when appropriate
5. Reduce generic assistant tone and optimize for sales clarity.

## Deliverables
Provide:
- prompt inventory
- revised prompt structure
- files changed
- examples of improved outputs

## Constraints
- Keep prompts concise and role-specific
- Avoid giant mixed-responsibility prompts
