# Expected State Shape

Use this as the target conversation state shape if the current repository lacks a proper structure.

```json
{
  "conversation_id": "string",
  "lead_id": "string",
  "current_stage": "new_lead | qualification | needs_discovery | package_recommendation | objection_handling | payment_discussion | closing | booked | follow_up | handoff_to_human",
  "current_intent": "greeting | package_inquiry | price_inquiry | availability_inquiry | payment_inquiry | booking_intent | objection | follow_up | unclear",
  "lead_temperature": "cold | warm | hot",
  "filled_slots": {
    "event_type": null,
    "event_date": null,
    "event_time_start": null,
    "event_time_end": null,
    "location": null,
    "package_interest": null,
    "budget": null,
    "payment_topic": null
  },
  "unresolved_questions": [],
  "last_user_message": null,
  "last_agent_message": null,
  "last_agent_question": null,
  "last_answered_topic": null,
  "next_best_action": null,
  "last_tool_result_summary": null
}
```

## Rules
- Filled slots must not be re-asked unless corrected by the user
- Payment inquiry should typically move the lead toward payment discussion or closing
- State must be updated every turn
- State must be read before generating any response
