# Phase 5 — Sales Stage Engine

Implement or repair explicit stage transitions for the AI sales flow.

## Objective
Stop the system from behaving like an endless Q&A bot and make it progress leads properly.

## Required Stages
At minimum:
- new_lead
- qualification
- needs_discovery
- package_recommendation
- objection_handling
- payment_discussion
- closing
- booked
- follow_up
- handoff_to_human

## Tasks
1. Inspect whether stages already exist.
2. If weak or missing, add stage logic and transition rules.
3. Define when the lead moves forward.
4. Prevent regression:
   - do not go back to broad discovery after payment inquiry
   - do not ask generic questions when the lead is near booking
5. Make response policy aware of the current stage.

## Laravel Suggestions
Possible classes:
- SalesStageService
- StageTransitionResolver
- LeadTemperatureResolver

## Deliverables
Provide:
- transition table
- implementation details
- files changed
- sample message -> stage transitions

## Constraints
- Keep transition rules readable
- Optimize for conversion flow, not generic assistant chat
