# AgentCore Contracts

This module now includes additive decision-contract scaffolding for the upcoming orchestrator refactor.

The new contracts are intentionally passive:

- DTOs in `DTOs/` capture normalized turn decisions, business payloads, shared conversation context, booking field candidates, and turn outcomes.
- Enums in `Enums/` define stable string values for final actions, response modes, turn outcomes, and booking field candidate status.
- Interfaces in `Contracts/` describe the future seams for decisioning, business payload generation, and shared context assembly.

Current runtime behavior is unchanged because these contracts are not wired into `AgentOrchestrator` yet. They exist to give the next refactor phase a deterministic shape, stable serialization, and unit-testable boundaries.
