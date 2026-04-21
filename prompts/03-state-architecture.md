# Phase 3 — Conversation State Architecture

Design and implement or repair the structured conversation state system.

## Objective
Ensure every incoming message is processed using structured state, not only raw chat history.

## Required State Shape
At minimum store:
- conversation_id
- lead_id
- current_stage
- current_intent
- lead_temperature
- filled_slots
- unresolved_questions
- last_user_message
- last_agent_message
- last_agent_question
- last_answered_topic
- next_best_action
- last_tool_result_summary

## Tasks
1. Inspect how memory currently works.
2. Check whether state exists in DB, cache, serialized JSON, or nowhere.
3. If missing or insufficient:
   - design a proper state model
   - create migration(s) if necessary
   - add service methods to load/update state each turn
4. Ensure state updates happen:
   - before interpretation
   - after intent/slot extraction
   - after response generation
5. Prevent already-filled slots from being asked again.

## Laravel Suggestions
Prefer one of:
- dedicated `conversation_states` table
- JSON column on conversations table if appropriate
- explicit state service with clean read/write methods

## Deliverables
Provide:
- state schema
- storage strategy
- update lifecycle
- files changed
- migration details
- edge cases

## Constraints
- Keep state explicit and debuggable
- Avoid hiding critical context only inside long summaries
