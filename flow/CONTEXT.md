# CONTEXT — Wedding Sales Agent SaaS

## Paste ini PERTAMA di setiap sesi Claude Code baru

---

## Sistem yang Sedang Dibangun

**Multi-tenant WhatsApp Sales Agent SaaS untuk vendor wedding.**
AI frontliner menjawab chat masuk, mengkualifikasi lead, merekomendasikan paket, dan menghandoff ke manusia di momen kritis.

---

## Tech Stack (Dikunci — Jangan Ganti)

| Komponen      | Teknologi                                                        |
| ------------- | ---------------------------------------------------------------- |
| Framework     | Laravel 13, PHP 8.4+                                             |
| Database      | MySQL 8+                                                         |
| Cache / Queue | Redis 7+                                                         |
| UI            | Blade + Livewire 3                                               |
| Queue Monitor | Laravel Horizon                                                  |
| Auth          | Laravel Sanctum + custom tenant guard                            |
| Roles         | Spatie Laravel Permission                                        |
| LLM           | openai-php/client — **gpt-4.1-mini saja**                        |
| WA Provider   | Baileys (@whiskeysockets/baileys) — Node.js sidecar              |
| Testing       | Pest PHP                                                         |
| Dev           | Docker (Windows): app, baileys-svc, mysql, redis, nginx, mailpit |
| Production    | VPS sendiri, Nginx + Supervisor                                  |

---

## Hard Rules (Wajib Dipatuhi Selalu)

```
TENANCY
- tenant_id WAJIB ada di semua tenant-scoped tables
- Setiap query tenant-scoped WAJIB filter by tenant_id
- Tidak boleh ada cross-tenant data leakage

ARSITEKTUR
- Modular monolith: app/Modules/{ModuleName}/
- Controller tipis — zero business logic di controller
- Zero business logic di Blade / Livewire component
- Modul tidak boleh query tabel modul lain langsung
- Komunikasi antar modul via service/event/action saja

LLM
- Hanya gpt-4.1-mini — jangan ganti model lain
- Jangan kirim full chat history ke LLM
- Context: max 6 pesan recent + summary + lead memory + max 3 knowledge items

QUEUE
- Semua operasi berat WAJIB async via queue
- Outbound WA, invoice send, LLM inference, follow-up = queue
- Priority: high (realtime reply) > medium (invoice/notif) > low (follow-up/summary)

SECURITY
- Jangan trust request payload untuk tenant ownership
- Resolve tenant dari auth context atau WA agent mapping
- Validate tenant ownership di setiap mutasi
```

---

## Folder Structure

```
app/Modules/
  Auth/           → Actions, DTOs, Http, Models, Policies, Providers, Services, Tests
  Tenancy/        → Actions, DTOs, Http/Middleware, Models, Services, Support, Tests
  WhatsApp/       → Actions, DTOs, Enums, Events, Http, Jobs, Listeners, Models, Repositories, Services, Support, Tests
  Leads/          → Actions, DTOs, Enums, Events, Jobs, Models, Repositories, Services, Tests
  Conversations/  → Actions, DTOs, Enums, Events, Jobs, Models, Repositories, Services, Tests
  AgentCore/      → Actions, DTOs, Enums, Jobs, Prompts, Services, Support, Tests
  Knowledge/      → Actions, DTOs, Enums, Http, Models, Repositories, Services, Tests
  Booking/        → Actions, DTOs, Enums, Http, Models, Repositories, Services, Tests
  Invoice/        → Actions, DTOs, Enums, Events, Jobs, Http, Models, Repositories, Services, Tests
  Subscription/   → Actions, DTOs, Enums, Events, Jobs, Http, Models, Repositories, Services, Tests
  Billing/        → Actions, DTOs, Enums, Events, Jobs, Http, Models, Repositories, Services, Tests
  Dashboard/      → Http/Controllers, Http/Livewire, Services, ViewModels, Tests
```

---

## Service Map (Semua Service yang Boleh Dibuat)

```
Auth:         AuthService, TenantMembershipService
Tenancy:      TenantResolver, TenantContext, TenantGuardService
Subscription: PlanService, SubscriptionService, SubscriptionEnforcementService, AgentSlotPolicyService
Billing:      BillingInvoiceService, BillingApprovalService, RenewalGenerationService
WhatsApp:     WhatsAppAgentService, PairingService, WebhookIngressService, OutboundDispatchService, AgentRoutingService
Leads:        LeadService, LeadQualificationService, LeadStageService, LeadRiskService
Conversations:ConversationService, MessageIngestService, ConversationSummaryService, ConversationStageService, LeadMemoryService
AgentCore:    AgentOrchestrator, PromptBuilder, ContextAssembler, GuardrailService,
              FollowUpPolicyService, DelayPolicyService, RiskPolicyService, LoggingLlmClient
Knowledge:    KnowledgeService, KnowledgeRetrievalService, KnowledgeCandidateService
Booking:      BookingSchemaService, BookingFieldValidationService, LeadBookingDataService
Invoice:      ClientInvoiceService, ClientInvoiceDispatchService, PaymentProofRoutingService
Dashboard:    DashboardMetricsService, AlertService
```

Jangan buat service di luar daftar ini tanpa konfirmasi.

---

## Database Tables (Referensi Cepat)

```
tenants, users, tenant_users, tenant_invitations
subscription_plans, subscriptions, billing_invoices, billing_payments
whatsapp_agents, whatsapp_pairings
leads, lead_profiles, conversations, messages
conversation_summaries, conversation_stage_transitions, lead_memories, handoff_requests
knowledge_items, knowledge_candidates
booking_form_templates, booking_fields, lead_booking_data
client_invoices, client_invoice_items
policy_events, outbound_message_queue, agent_memories
notifications, llm_usage_logs, audit_logs
```

---

## Lead Status (Urutan)

```
NEW → QUALIFIED → INTERESTED → HOT → READY_FOR_HUMAN → CLOSED_WON / CLOSED_LOST
```

---

## Conversation Stage (Playbook, Per-Conversation)

Terpisah dari Lead Status. Lead Status = state bisnis; Conversation Stage = state playbook percakapan agent.

```
GREETING → DISCOVERY → NEEDS_MATCHING → PACKAGE_PRESENTATION
                     ↘ OBJECTION_HANDLING ↗
                                        ↓
                                    SOFT_CLOSE → CLOSED
(any)                               ↘ HANDOFF → CLOSED
```

Tujuan: prevent re-ask, keep conversation direction coherent.
State disimpan di `conversations.stage` + `asked_fields` + `next_expected_field`.
Transisi di-log ke `conversation_stage_transitions` (audit).

---

## Queue Priority

```
high:   realtime inbound reply
medium: invoice send, handoff notification, billing alert
low:    auto follow-up, summary refresh, knowledge candidate, usage snapshot
```

---

## Anti-Ban Rules (Selalu Diterapkan)

```
- Inbound-first only — tidak pernah kirim pesan pertama ke nomor baru
- Max 2 auto follow-up per lead
- FU-1 minimal 18 jam, FU-2 minimal 48 jam setelah FU-1
- Stop jika: opt-out, negative sentiment, 2 FU tidak dibalas, handoff aktif, agent disconnect
- Natural delay: 2-5 detik pendek, 4-10 detik rekomendasi paket
- Adaptive throttle per nomor agent — bukan fixed global delay
```

---

## Output Format per Phase

Setiap phase harus menghasilkan:

- Migrations (dengan semua indexes)
- Models (dengan relationships dan scopes)
- Enums (jika relevan)
- Services (sesuai service map)
- Actions (satu use-case per action)
- Jobs/Events/Listeners (jika relevan)
- Pest tests (cover critical paths)

---

## Setelah Selesai Tiap Phase, Selalu Laporkan:

1. Daftar semua file yang dibuat beserta path-nya
2. Semua indexes yang ditambahkan
3. Cara tenant isolation diterapkan di phase ini
4. Queue boundaries yang ditambahkan
5. Unresolved TODOs saja (jangan yang sudah selesai)
