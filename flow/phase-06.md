# Phase 06 — Agent Core

## LLM Orchestration, Context Assembly, Guardrails, Anti-Ban

> Paste CONTEXT.md + CONTEXT-LLM.md sebelum ini. Phase 01–05 harus selesai.
> Phase ini paling kompleks — siapkan sesi Claude Code khusus.

---

## Yang Dibangun di Phase Ini

1. AgentOrchestrator — entry point utama
2. ContextAssembler — susun context untuk LLM
3. PromptBuilder — bangun system prompt per mode
4. LLM client abstraction + logging wrapper
5. GuardrailService — validasi sebelum send
6. FollowUpPolicyService — batas follow-up
7. DelayPolicyService — natural typing delay
8. RiskPolicyService — risk score dari conversation
9. Lengkapi RefreshConversationSummaryJob dari Phase 4

---

## LLM Client Abstraction

```php
// app/Modules/AgentCore/Contracts/LlmClientInterface.php
interface LlmClientInterface {
    public function complete(array $messages, array $options = []): LlmResponse;
}

// app/Modules/AgentCore/DTOs/LlmResponse.php
class LlmResponse {
    public string $content;
    public int $promptTokens;
    public int $completionTokens;
    public int $totalTokens;
    public string $model;
}

// app/Modules/AgentCore/Services/OpenAiLlmClient.php
// Gunakan openai-php/client
// Model: gpt-4.1-mini (hardcoded)

// app/Modules/AgentCore/Services/LoggingLlmClient.php
// Wrapper: panggil inner client, catat ke llm_usage_logs, return response
```

### llm_usage_logs migration

```sql
id, tenant_id (FK), conversation_id (FK nullable),
mode (enum: classifier|response|summary),
prompt_tokens (int), completion_tokens (int), total_tokens (int),
model (varchar default 'gpt-4.1-mini'),
created_at
INDEX: (tenant_id, created_at), INDEX: (tenant_id, mode, created_at)
```

---

## ContextAssembler

```php
// app/Modules/AgentCore/Services/ContextAssembler.php

assemble(Lead $lead, Conversation $conv, string $mode, string $intent = ''): array
  // Return array siap dikirim ke LLM sebagai messages[]
  // Urutan:
  // [system] → PromptBuilder::buildSystem($tenant, $mode)
  // [user]   → buildContextBlock($lead, $conv, $intent)

buildContextBlock(...): string
  // 1. TENANT POLICY: quiet_hours, follow_up_limits, automation_paused
  // 2. LEAD MEMORY: LeadMemoryService::getSnapshot($lead)
  // 3. MISSING BOOKING FIELDS: hanya jika ada field required kosong
  // 4. KNOWLEDGE: KnowledgeRetrievalService::getRelevantSubset($tenant, $intent, 3)
  // 5. RECENT MESSAGES: ConversationService::getRecentMessages($conv, 6)
  // 6. CONVERSATION SUMMARY: ConversationSummaryService::getSummary($conv)
  // 7. TASK: instruksi mode aktif

// Token limits: total input target < 2000 tokens
// Jika knowledge terlalu panjang: trim ke 300 chars per item
// Jika recent messages terlalu banyak: ambil 6 terbaru saja
```

---

## PromptBuilder

```php
// app/Modules/AgentCore/Services/PromptBuilder.php

buildSystem(Tenant $tenant, string $mode): string
```

### System Prompt — Mode Classifier

```
Kamu adalah classifier untuk wedding sales agent.
Analisa pesan user dan kembalikan JSON saja (tanpa teks lain):
{
  "intent": "greeting|tanya_harga|tanya_paket|bandingkan_paket|availability|custom_package|ready_to_book|payment_proof|complaint|opt_out|other",
  "sentiment": "positive|neutral|negative",
  "extracted_fields": {"name":null,"event_date":null,"location":null,"budget":null,"service_type":null,"guest_count":null},
  "needs_handoff": false,
  "handoff_reason": null,
  "confidence": 0.0
}
needs_handoff = true jika: availability, custom_package, ready_to_book, payment_proof, complaint, opt_out
```

### System Prompt — Mode Response (Sales Brain)

```
Kamu adalah sales agent wedding yang ramah dan profesional.
Vendor: {tenant.name}

RULES WAJIB:
- Jawab singkat dan natural, seperti manusia
- Gunakan HANYA informasi yang ada di context — jangan karang
- JANGAN konfirmasi ketersediaan tanggal — arahkan ke admin
- JANGAN tawarkan custom package final — arahkan ke admin
- JANGAN sebut harga yang tidak ada di knowledge
- Jika perlu handoff: "Untuk hal ini, tim kami akan segera menghubungi kamu ya"
- Bahasa: ikuti bahasa user (Indonesia atau Inggris)
- Max 150 kata per response
```

### System Prompt — Mode Summary

```
Buat ringkasan percakapan ini untuk digunakan sebagai context AI agent.
Format output:
SUMMARY: [2-3 kalimat ringkasan]
LEAD_STAGE: [new|qualified|interested|hot|ready_for_human]
MEMORY_UPDATES:
- name: [value atau null]
- event_date: [value atau null]
- location: [value atau null]
- budget: [value atau null]
- service_type: [value atau null]
FOLLOW_UP_ELIGIBLE: [true|false]
FOLLOW_UP_REASON: [jika false]
```

---

## AgentOrchestrator

```php
// app/Modules/AgentCore/Services/AgentOrchestrator.php

handleInbound(Message $message, Lead $lead, Conversation $conv): void
  // 1. Guardrail check: GuardrailService::check($lead, $conv)
  //    → Jika blocked: return (tidak proses)
  // 2. Classify: runClassifier($message, $lead, $conv)
  //    → Parse JSON output
  // 3. Handle intent:
  //    - needs_handoff = true → HandoffRequestService::create(), stop
  //    - opt_out → LeadService::pauseAutomation(), stop
  //    - negative sentiment → pause + handoff
  // 4. Update lead memory: LeadMemoryService::upsert($lead, extracted_fields)
  // 5. Update lead stage: LeadStageService::advanceStage()
  // 6. Build response: runResponse($message, $lead, $conv, $intent)
  // 7. Apply delay: DelayPolicyService::getDelay($response_text)
  // 8. Dispatch SendOutboundMessageJob dengan delay
  // 9. Async: dispatch RefreshConversationSummaryJob (queue: low)

runClassifier(Message $message, Lead $lead, Conversation $conv): array
  // ContextAssembler::assemble($lead, $conv, 'classifier', '')
  // LlmClient::complete(messages)
  // Parse JSON dari response
  // Return array

runResponse(Message $message, Lead $lead, Conversation $conv, string $intent): string
  // ContextAssembler::assemble($lead, $conv, 'response', $intent)
  // LlmClient::complete(messages)
  // Return content string

generateSummary(Conversation $conv): void
  // ContextAssembler untuk mode summary
  // LlmClient::complete()
  // Parse output, update LeadMemory + ConversationSummary
```

---

## GuardrailService

```php
// app/Modules/AgentCore/Services/GuardrailService.php

check(Lead $lead, Conversation $conv): GuardrailResult
  // GuardrailResult: { blocked: bool, reason: string }

// Checks (urutan):
// 1. lead.automation_paused → blocked
// 2. SubscriptionEnforcementService::assertCanSendOutbound() → catch → blocked
// 3. AgentRoutingService: assigned agent masih connected? → jika tidak → blocked
// 4. Quiet hours: jam saat ini antara quiet_hours_start dan quiet_hours_end → blocked
// 5. Risk score > threshold (default 80) → blocked, flag untuk review
```

---

## FollowUpPolicyService

```php
// app/Modules/AgentCore/Services/FollowUpPolicyService.php

canSendFollowUp(Lead $lead): FollowUpCheckResult
  // Ambil lead_automation_states atau dari lead_memories custom_fields
  // Check: follow_up_count >= 2 → tidak eligible
  // Check FU-1: follow_up_count == 0 AND now - last_message_at >= 18 hours
  // Check FU-2: follow_up_count == 1 AND now - fu1_sent_at >= 48 hours

recordFollowUpSent(Lead $lead): void
  // Increment follow_up_count
  // Update fu1_sent_at atau fu2_sent_at

resetFollowUpState(Lead $lead): void
  // Reset ke 0 saat lead kirim pesan baru
```

---

## DelayPolicyService

```php
// app/Modules/AgentCore/Services/DelayPolicyService.php

getDelay(string $responseText): int  // return detik
  // Hitung jumlah kata
  // < 30 kata: random(2, 5)
  // 30-100 kata: random(4, 10)
  // > 100 kata: random(8, 15)
```

---

## RiskPolicyService

```php
// app/Modules/AgentCore/Services/RiskPolicyService.php

calculateRisk(Lead $lead, array $classifierOutput): int  // 0-100
  // Faktor: negative sentiment (+30), complaint intent (+25),
  //         high follow_up_count (+20), opt_out keyword (+50)
  // Update lead.risk_score

isHighRisk(Lead $lead): bool  // risk_score > 70
```

---

## Lengkapi RefreshConversationSummaryJob

```php
// Dari Phase 4, tambahkan implementasi:
public function handle(AgentOrchestrator $orchestrator): void {
    $conv = Conversation::find($this->conversationId);
    if ($conv) {
        $orchestrator->generateSummary($conv);
    }
}
```

---

## Tests (Pest)

```
✓ ContextAssembler: tidak kirim lebih dari 6 pesan recent
✓ ContextAssembler: tidak include data knowledge lain tenant
✓ ContextAssembler: total token estimate tidak melebihi 2000
✓ GuardrailService: paused lead → blocked
✓ GuardrailService: expired subscription → blocked
✓ GuardrailService: quiet hours → blocked
✓ FollowUpPolicyService: count >= 2 → tidak eligible
✓ FollowUpPolicyService: FU-1 belum 18 jam → tidak eligible
✓ RiskPolicyService: negative sentiment → skor naik
✓ AgentOrchestrator: needs_handoff → HandoffRequest dibuat, stop proses
✓ AgentOrchestrator: opt_out → automation paused, stop proses
✓ LoggingLlmClient: setiap call tercatat di llm_usage_logs
```

---

## Setelah Selesai, Laporkan

1. Semua file yang dibuat
2. Cara context assembly bekerja (urutan, token budget)
3. Cara guardrail mencegah pengiriman yang salah
4. Cara LLM logging bekerja
5. TODOs yang belum resolved
