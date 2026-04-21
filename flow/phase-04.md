# Phase 04 — Leads, Conversations, Messages, Media

## Lead Lifecycle, Chat Storage, Memory, Handoff, Media Handling

> Pastikan CONTEXT.md sudah di-paste. Phase 01–03 harus selesai.

---

## Yang Dibangun di Phase Ini

1. Leads + lead profiles + lead memories
2. Conversations + messages (text dan media)
3. Conversation summaries
4. Handoff requests
5. Media handling untuk inbound non-text
6. Semua service untuk ingest dan process chat

---

## Migrations

### leads

```sql
id, tenant_id (FK), whatsapp_agent_id (FK nullable),
phone_e164 (varchar 20), name (varchar nullable),
status (enum: new|qualified|interested|hot|ready_for_human|closed_won|closed_lost),
interest_score (tinyint default 0), risk_score (tinyint default 0),
automation_paused (bool default false),
last_message_at (timestamp nullable),
created_at, updated_at
UNIQUE: (tenant_id, phone_e164)
INDEX: (tenant_id, status), INDEX: (tenant_id, last_message_at)
INDEX: (tenant_id, whatsapp_agent_id)
```

### lead_profiles

```sql
id, tenant_id (FK), lead_id (FK unique),
event_date (date nullable), event_location (varchar nullable),
budget_min (int nullable), budget_max (int nullable),
service_type (varchar nullable), guest_count (int nullable),
notes (text nullable),
created_at, updated_at
INDEX: (tenant_id, lead_id)
```

### conversations

```sql
id, tenant_id (FK), lead_id (FK), whatsapp_agent_id (FK nullable),
status (enum: active|closed|handoff),
is_human_takeover (bool default false),
closed_at (timestamp nullable),
created_at, updated_at
INDEX: (tenant_id, lead_id), INDEX: (tenant_id, status)
INDEX: (tenant_id, whatsapp_agent_id), INDEX: (tenant_id, updated_at)
```

### messages

```sql
id, tenant_id (FK), conversation_id (FK), lead_id (FK),
direction (enum: inbound|outbound),
message_type (enum: text|image|video|audio|document|sticker|reaction|system),
content (text nullable),
media_url (varchar 500 nullable), media_mime (varchar 100 nullable),
media_filename (varchar 255 nullable), wa_message_id (varchar 100 nullable),
status (enum: pending|sent|delivered|read|failed),
is_from_ai (bool default false),
created_at, updated_at
INDEX: (conversation_id, created_at), INDEX: (tenant_id, created_at)
INDEX: (tenant_id, direction, created_at)
INDEX: (lead_id, created_at)
```

### conversation_summaries

```sql
id, tenant_id (FK), conversation_id (FK unique),
summary_text (text), last_summarized_at (timestamp),
message_count_at_summary (int default 0),
created_at, updated_at
INDEX: (tenant_id, conversation_id)
```

### lead_memories

```sql
id, tenant_id (FK), lead_id (FK unique),
name (varchar nullable), event_date (date nullable),
event_location (varchar nullable),
budget_min (int nullable), budget_max (int nullable),
service_type (varchar nullable), guest_count (int nullable),
preferred_packages (json nullable), objections (json nullable),
custom_fields (json nullable),
updated_at (timestamp), created_at
INDEX: (tenant_id, lead_id)
```

### handoff_requests

```sql
id, tenant_id (FK), lead_id (FK), conversation_id (FK),
reason (enum: availability_check|custom_package|ready_to_book|payment_proof|complaint|negative_sentiment|other),
reason_detail (text nullable),
status (enum: pending|resolved|dismissed),
resolved_by (FK users nullable), resolved_at (timestamp nullable),
summary_for_admin (text nullable),
created_at, updated_at
INDEX: (tenant_id, status), INDEX: (tenant_id, lead_id)
INDEX: (tenant_id, created_at)
```

---

## Enums

```php
LeadStatus:     new | qualified | interested | hot | ready_for_human | closed_won | closed_lost
MessageType:    text | image | video | audio | document | sticker | reaction | system
MessageDirection: inbound | outbound
MessageStatus:  pending | sent | delivered | read | failed
HandoffReason:  availability_check | custom_package | ready_to_book | payment_proof | complaint | negative_sentiment | other
HandoffStatus:  pending | resolved | dismissed
ConversationStatus: active | closed | handoff
```

---

## Services

### LeadService

```php
findOrCreateByPhone(Tenant $tenant, string $phone, WhatsAppAgent $agent): Lead
updateStatus(Lead $lead, LeadStatus $status): void
pauseAutomation(Lead $lead): void
resumeAutomation(Lead $lead): void
getHotLeads(Tenant $tenant): Collection   // HOT + READY_FOR_HUMAN
```

### LeadStageService

```php
advanceStage(Lead $lead, LeadStatus $newStatus): void
  // Dispatch HotLeadAlertNotification jika stage = HOT atau READY_FOR_HUMAN
shouldHandoff(Lead $lead, string $intent): bool
```

### ConversationService

```php
openOrResume(Lead $lead, WhatsAppAgent $agent): Conversation
  // Cari conversation active untuk lead ini
  // Jika tidak ada: buat baru
  // Jika ada + handoff aktif: return yang ada (jangan buat baru)
close(Conversation $conv): void
markHandoff(Conversation $conv): void
getRecentMessages(Conversation $conv, int $limit = 6): Collection
```

### MessageIngestService

```php
ingestInbound(array $webhookData): Message
  // Resolve tenant dari agent phone
  // LeadService::findOrCreateByPhone()
  // ConversationService::openOrResume()
  // Simpan message ke DB
  // Update lead.last_message_at
  // Return message untuk diproses lebih lanjut

isMediaMessage(Message $message): bool
getMediaAutoResponse(MessageType $type): string
```

### MediaHandlerService (app/Modules/Conversations/Services/)

```php
handleInboundMedia(Message $message, Tenant $tenant): void
  // Download + simpan ke storage/app/tenants/{id}/media/{year}/{month}/{wa_message_id}.ext
  // Update message.media_url dengan path lokal
  // Cek payment proof: jika ada client_invoice sent + lead HOT/READY_FOR_HUMAN
  //   → PaymentProofDetectedAction::run()

storagePath(Tenant $tenant, Message $message): string
```

### PaymentProofDetectedAction

```php
// Jika lead punya client_invoice dengan status = sent:
// 1. Buat HandoffRequest (reason: payment_proof)
// 2. Update message media_url ke storage path
// 3. Dispatch HandoffCreatedNotification
// 4. Return auto-reply text: "Bukti transfer sudah kami terima! Tim kami akan verifikasi segera."
```

### ConversationSummaryService

```php
refresh(Conversation $conv): ConversationSummary
  // Ambil semua messages conversation (hanya content text saja)
  // Dispatch RefreshConversationSummaryJob ke queue low
getSummary(Conversation $conv): ?string
```

### LeadMemoryService

```php
upsert(Lead $lead, array $extractedFields): void
  // Merge ke existing lead_memory record
  // Hanya update field yang tidak null dari extractedFields
getSnapshot(Lead $lead): array   // Return untuk dikirim ke LLM context
```

### HandoffRequestService

```php
create(Lead $lead, Conversation $conv, HandoffReason $reason, string $detail = null): HandoffRequest
  // Buat record
  // Update conversation.status = handoff
  // LeadStageService::advanceStage(READY_FOR_HUMAN)
  // Dispatch HandoffCreatedNotification ke vendor admin
  // Pause automation (lead.automation_paused = true)

resolve(HandoffRequest $req, User $user): void
dismiss(HandoffRequest $req, User $user): void
getPendingForTenant(Tenant $tenant): Collection
```

---

## Jobs

### RefreshConversationSummaryJob (queue: low)

```php
// Input: conversation_id
// Ambil semua messages text dalam conversation
// Panggil LLM mode Summary via AgentOrchestrator (di phase 6)
// Simpan ke conversation_summaries
// TODO: akan diisi implementasi LLM-nya di phase 6
```

---

## Media Storage Path Convention

```
storage/app/tenants/{tenant_id}/
  media/{year}/{month}/{wa_message_id}.{ext}   ← foto, video, audio, doc dari lead
  invoices/{client_invoice_id}.pdf              ← invoice PDF
  billing/{billing_invoice_id}.pdf              ← billing invoice PDF
  proofs/{billing_invoice_id}_proof.{ext}       ← bukti bayar subscription
```

---

## Tests (Pest)

```
✓ LeadService: findOrCreate — nomor baru buat record, nomor lama return existing
✓ LeadService: tenant isolation — nomor sama di tenant berbeda = lead berbeda
✓ ConversationService: openOrResume — resume jika ada active, buat baru jika tidak ada
✓ MessageIngestService: inbound text tersimpan dengan benar (tenant, lead, conv)
✓ MessageIngestService: inbound media tersimpan dengan message_type yang benar
✓ MediaHandlerService: payment proof detection trigger handoff
✓ HandoffRequestService: buat handoff → automation paused, notif dispatch
✓ LeadMemoryService: upsert tidak overwrite field yang sudah ada dengan null
✓ ConversationService::getRecentMessages: max 6, urutan terbaru dulu
```

---

## Setelah Selesai, Laporkan

1. Semua file yang dibuat beserta path
2. Cara media disimpan dan path convention
3. Payment proof detection flow
4. Semua indexes yang ditambahkan
5. TODOs yang belum resolved (terutama LLM di RefreshConversationSummaryJob)
