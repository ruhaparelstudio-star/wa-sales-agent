# Phase 05 — Knowledge & Booking Schema

## Knowledge Base, Candidates, Semi-Dynamic Booking Form

> Pastikan CONTEXT.md sudah di-paste. Phase 01–04 harus selesai.

---

## Yang Dibangun di Phase Ini

1. Knowledge items (FAQ, paket, policy, portfolio)
2. Knowledge candidates (dari chat, butuh approval admin)
3. Booking form schema (semi-dynamic, per form type)
4. Lead booking data (data yang diisi lead)

---

## Migrations

### knowledge_items

```sql
id, tenant_id (FK), type (enum: faq|package|policy|portfolio|objection),
title (varchar), content (text), tags (json nullable),
is_active (bool default true), sort_order (int default 0),
created_at, updated_at
INDEX: (tenant_id, type, is_active), INDEX: (tenant_id, sort_order)
```

### knowledge_candidates

```sql
id, tenant_id (FK), conversation_id (FK nullable),
proposed_title (varchar), proposed_content (text),
proposed_type (varchar), source_note (varchar nullable),
status (enum: pending|approved|rejected),
reviewed_by (FK users nullable), reviewed_at (timestamp nullable),
promoted_to_item_id (FK knowledge_items nullable),
created_at, updated_at
INDEX: (tenant_id, status)
```

### booking_form_templates

```sql
id, tenant_id (FK), form_type (enum: inquiry|booking),
name (varchar), is_active (bool default true),
created_at, updated_at
UNIQUE: (tenant_id, form_type, is_active) -- satu aktif per type
INDEX: (tenant_id, form_type, is_active)
```

### booking_fields

```sql
id, tenant_id (FK), template_id (FK), field_key (varchar),
label (varchar), field_type (enum: text|date|number|select|textarea),
options (json nullable), is_required (bool default false),
sort_order (int default 0),
created_at, updated_at
INDEX: (tenant_id, template_id, sort_order)
```

### lead_booking_data

```sql
id, tenant_id (FK), lead_id (FK), form_type (enum: inquiry|booking),
field_key (varchar), field_value (text nullable),
created_at, updated_at
UNIQUE: (lead_id, form_type, field_key)
INDEX: (tenant_id, lead_id, form_type)
```

---

## Enums

```php
KnowledgeType:       faq | package | policy | portfolio | objection
KnowledgeStatus:     pending | approved | rejected
FormType:            inquiry | booking
BookingFieldType:    text | date | number | select | textarea
```

---

## Services

### KnowledgeService

```php
getAll(Tenant $tenant, ?KnowledgeType $type = null): Collection
create(Tenant $tenant, array $data): KnowledgeItem
update(KnowledgeItem $item, array $data): KnowledgeItem
toggle(KnowledgeItem $item): void
```

### KnowledgeRetrievalService

```php
getRelevantSubset(Tenant $tenant, string $intent, int $limit = 3): Collection
  // Filter by tenant_id + is_active
  // Prioritas: type = package jika intent = tanya_harga
  //            type = faq untuk pertanyaan umum
  //            type = objection untuk handling keberatan
  // Kembalikan max $limit items sebagai array snippet
  // Cache per tenant + intent (TTL 5 menit)

getCachedHeaders(Tenant $tenant): Collection
  // Semua active knowledge — hanya id + title + type (untuk LLM reference list)
  // Cache per tenant (TTL 10 menit, invalidate saat ada update)
```

### KnowledgeCandidateService

```php
submit(Tenant $tenant, ?Conversation $conv, array $data): KnowledgeCandidate
approve(KnowledgeCandidate $candidate, User $user): KnowledgeItem
  // Buat KnowledgeItem dari candidate
  // Update candidate: status=approved, promoted_to_item_id
reject(KnowledgeCandidate $candidate, User $user): void
getPending(Tenant $tenant): Collection
```

### BookingSchemaService

```php
getActiveSchema(Tenant $tenant, FormType $type): ?BookingFormTemplate
  // Cache per tenant + form_type (TTL 10 menit)
  // Include booking_fields dalam eager load, ordered by sort_order

getFieldsForContext(Tenant $tenant, Lead $lead, FormType $type): array
  // Return fields yang belum diisi lead (untuk LLM context)

invalidateCache(Tenant $tenant): void
  // Hapus cache saat schema diupdate
```

### BookingFieldValidationService

```php
validate(array $data, BookingFormTemplate $template): array  // return errors
```

### LeadBookingDataService

```php
upsert(Lead $lead, FormType $type, array $fields): void
  // Insert or update per field_key
getForLead(Lead $lead, FormType $type): array  // key-value pairs
getMissingRequired(Lead $lead, FormType $type): array  // field yang required tapi kosong
```

---

## Cache Keys (Redis)

```
knowledge:tenant:{id}:headers               TTL 10 menit
knowledge:tenant:{id}:subset:{intent}       TTL 5 menit
booking:schema:tenant:{id}:{form_type}      TTL 10 menit
```

Invalidate saat admin update knowledge atau booking schema.

---

## Tests (Pest)

```
✓ KnowledgeRetrievalService: tidak return data tenant lain
✓ KnowledgeRetrievalService: max 3 items, tipe sesuai intent
✓ KnowledgeCandidateService: approve → promote ke knowledge_items
✓ BookingSchemaService: field order sesuai sort_order
✓ LeadBookingDataService: upsert tidak duplikat field_key
✓ getMissingRequired: return field kosong yang required
✓ Cache: invalid setelah update knowledge
```

---

## Setelah Selesai, Laporkan

1. Semua file yang dibuat
2. Cache keys yang dipakai
3. Cara cross-tenant isolation dijaga
4. TODOs yang belum resolved
