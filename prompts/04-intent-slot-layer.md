# Phase 4 — Intent and Slot Layer

Implement or repair the interpretation layer that extracts user intent and slots before response generation.

## Objective
Separate understanding from speaking.

## Required Intents
At minimum:
- greeting
- package_inquiry
- price_inquiry
- availability_inquiry
- payment_inquiry
- booking_intent
- objection
- follow_up
- unclear

## Required Slots
At minimum:
- event_type
- event_date
- event_time_start
- event_time_end
- location
- package_interest
- budget
- payment_topic

## Tasks
1. Find how the current system interprets user messages.
2. Extract or create a dedicated interpretation step.
3. Make sure interpretation runs before final response generation.
4. Save intent and slots into structured state.
5. Add confidence or certainty handling where practical.
6. Prevent generic fallback if a clear intent exists.

## Laravel Suggestions
Use explicit services such as:
- IntentExtractionService
- SlotExtractionService
- ConversationInterpretationService

## Deliverables
Provide:
- interpretation flow
- extraction logic
- files changed
- how state is updated
- examples of before vs after behavior

## Constraints
- Use deterministic rules for stable patterns when possible
- Do not overuse the LLM when simple matching solves the case
