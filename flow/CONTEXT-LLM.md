# CONTEXT-LLM — Agent Core & LLM Orchestration

## Paste ini sebelum phase-06.md

---

## LLM Architecture

Model tunggal: `gpt-4.1-mini`
Client di-wrap dalam interface untuk memudahkan swap di masa depan.

```
LlmClientInterface
  └── LoggingLlmClient        (wrapper: catat usage ke llm_usage_logs)
        └── OpenAiLlmClient   (implementasi aktif)
```

---

## 3 Mode Prompt

### Mode 1 — Classifier

**Input:** Pesan user + context ringkas
**Output:** JSON saja, tidak ada teks lain

```json
{
  "intent": "tanya_harga | greeting | availability | custom_package | ready_to_book | payment_proof | complaint | opt_out | other",
  "sentiment": "positive | neutral | negative",
  "extracted_fields": {
    "name": null,
    "event_date": null,
    "location": null,
    "budget": null,
    "service_type": null,
    "guest_count": null
  },
  "needs_handoff": false,
  "handoff_reason": null,
  "confidence": 0.95
}
```

### Mode 2 — Response (Sales Brain)

**Input:** Context lengkap (lihat Context Assembly di bawah)
**Output:** Teks natural saja, tidak ada JSON

Rules response:

- Concise, human-like, bahasa sesuai user (Indonesia/Inggris)
- Jangan sebut harga yang tidak ada di knowledge
- Jangan konfirmasi availability — arahkan ke admin
- Jangan tawarkan custom package final — arahkan ke admin
- Jangan fabricate price atau janji apapun
- Gunakan hanya knowledge yang diberikan dalam context

### Mode 3 — Summary

**Input:** Full conversation summary request
**Output:** Structured text

```
SUMMARY: [ringkasan percakapan 2-3 kalimat]
LEAD_STAGE: [NEW|QUALIFIED|INTERESTED|HOT|READY_FOR_HUMAN]
MEMORY_UPDATES:
- name: [value atau null]
- event_date: [value atau null]
- location: [value atau null]
- budget: [value atau null]
- service_type: [value atau null]
FOLLOW_UP_ELIGIBLE: [true|false]
FOLLOW_UP_REASON: [alasan jika false]
```

---

## Context Assembly — Urutan Wajib

```
1. [TENANT POLICY]
   - quiet_hours_start, quiet_hours_end
   - follow_up_count_used, follow_up_max
   - automation_paused: true/false

2. [LEAD MEMORY]
   - name, event_date, location, budget, service_type, guest_count
   - lead_stage, risk_score

3. [BOOKING FIELDS MISSING] (opsional, hanya jika ada field kosong penting)
   - field yang belum terisi

4. [KNOWLEDGE] (max 3 item, paling relevan dengan intent)
   - FAQ snippet / package info / policy

5. [RECENT MESSAGES] (max 6 pesan, terbaru dulu)
   - role: user/assistant
   - content

6. [CONVERSATION SUMMARY]
   - summary text dari conversation_summaries

7. [TASK INSTRUCTION]
   - Mode yang aktif: classifier | response | summary
```

**Yang TIDAK BOLEH dikirim ke LLM:**

- Full chat history (lebih dari 6 pesan recent)
- Full knowledge dump tenant
- Data tenant lain
- Internal system fields yang tidak relevan

---

## Token Budget Target

| Bagian               | Max Tokens |
| -------------------- | ---------- |
| System prompt        | ~400       |
| Tenant policy        | ~50        |
| Lead memory          | ~100       |
| Knowledge subset     | ~300       |
| Recent messages (6)  | ~600       |
| Conversation summary | ~150       |
| Task instruction     | ~50        |
| **Total input**      | **~1650**  |
| **Max output**       | **~400**   |

---

## Usage Logging

Setiap LLM call wajib dicatat ke `llm_usage_logs`:

```php
llm_usage_logs:
  tenant_id, conversation_id, mode (classifier/response/summary),
  prompt_tokens, completion_tokens, total_tokens,
  model (gpt-4.1-mini), created_at
```

Indexes: `(tenant_id, created_at)`, `(tenant_id, mode, created_at)`

---

## GuardrailService — Cek Sebelum Send

```
Sebelum setiap outbound message:
1. Subscription aktif? → jika expired: block outbound
2. Agent masih connected? → jika disconnect: stop, catat
3. Quiet hours? → jika ya: tunda ke setelah jam operasional
4. Follow-up limit? → FU-1: sudah >= 18 jam? FU-2: sudah >= 48 jam dari FU-1?
5. Max follow-up (2) tercapai? → stop automation
6. Handoff aktif? → stop automation
7. Risk score > threshold? → flag untuk review
8. Negative sentiment? → pause automation, buat handoff
```

---

## FollowUpPolicyService

```
State per lead (di lead_automation_states atau agent_memories):
  follow_up_count: 0 | 1 | 2
  last_message_at: timestamp
  follow_up_1_sent_at: timestamp | null
  follow_up_2_sent_at: timestamp | null
  automation_paused: bool

Rules:
  FU-1 eligible: follow_up_count == 0 AND now - last_message_at >= 18 hours
  FU-2 eligible: follow_up_count == 1 AND now - follow_up_1_sent_at >= 48 hours
  Stop: follow_up_count >= 2
```

---

## DelayPolicyService

```
Natural delay sebelum send (simulates typing):
  Jawaban pendek (< 50 kata): random 2-5 detik
  Rekomendasi paket (50-150 kata): random 4-10 detik
  Pesan panjang (> 150 kata): random 8-15 detik

Implementasi: job delay via queue atau sleep dalam SendOutboundJob
```

---

## Anti-Ban Stop Conditions

Segera pause automation dan buat HandoffRequest jika:

- User kirim: "stop", "tidak mau", "unsubscribe", "hapus", "jangan hubungi"
- Sentiment classifier: "negative" dengan confidence > 0.8
- 2 follow-up berturut-turut tidak dibalas
- Lead status sudah READY_FOR_HUMAN
- Agent terdeteksi disconnect
