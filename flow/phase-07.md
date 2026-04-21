# Phase 07 — Client Invoice Workflow

## Create/Upload Invoice, Send via WA, Payment Proof Routing

> Pastikan CONTEXT.md sudah di-paste. Phase 01–06 harus selesai.

---

## Yang Dibangun di Phase Ini

1. Client invoice (tagihan ke client/lead — bukan billing subscription)
2. Admin bisa create invoice manual atau upload PDF
3. Invoice dikirim otomatis via WhatsApp agent yang assigned ke lead
4. Payment proof dari WA → auto-create handoff untuk verifikasi admin

---

## Migrations

### client_invoices

```sql
id, tenant_id (FK), lead_id (FK), whatsapp_agent_id (FK nullable),
invoice_number (varchar unique per tenant), invoice_type (enum: created|uploaded),
status (enum: draft|sent|delivered|viewed|paid|overdue|cancelled),
amount (decimal 12,2 nullable), currency (varchar 3 default 'IDR'),
due_date (date nullable),
pdf_path (varchar nullable),         ← path untuk uploaded atau generated PDF
intro_message (text nullable),       ← pesan intro sebelum invoice dikirim
wa_message_id (varchar nullable),    ← WA message ID setelah terkirim
sent_at (timestamp nullable), delivered_at (timestamp nullable),
paid_at (timestamp nullable),
notes (text nullable),
created_by (FK users), created_at, updated_at
INDEX: (tenant_id, lead_id, status), INDEX: (tenant_id, status)
INDEX: (tenant_id, due_date)
```

### client_invoice_items

```sql
id, invoice_id (FK), description (varchar),
quantity (int default 1), unit_price (decimal 12,2),
total_price (decimal 12,2),
sort_order (int default 0),
created_at, updated_at
```

---

## Enums

```php
ClientInvoiceType:   created | uploaded
ClientInvoiceStatus: draft | sent | delivered | viewed | paid | overdue | cancelled
```

---

## Services

### ClientInvoiceService

```php
createFromItems(Tenant $tenant, Lead $lead, User $creator, array $items, array $meta): ClientInvoice
  // meta: { due_date, intro_message, notes }
  // Hitung total dari items
  // Buat invoice + items
  // Generate invoice_number: INV-{tenant_id}-{YYYYMM}-{sequential}
  // Status: draft

attachUploadedPdf(Tenant $tenant, Lead $lead, User $creator, UploadedFile $file, array $meta): ClientInvoice
  // Simpan PDF ke storage/app/tenants/{id}/invoices/{invoice_id}.pdf
  // Buat invoice record dengan type=uploaded
  // Status: draft

getInvoicesForLead(Tenant $tenant, Lead $lead): Collection
getInvoiceForTenant(int $invoiceId, Tenant $tenant): ClientInvoice  // validate ownership
markAsPaid(ClientInvoice $invoice): void
```

### ClientInvoiceDispatchService

```php
dispatch(ClientInvoice $invoice): void
  // Validasi: invoice.status = draft, lead punya assigned agent
  // Resolve agent: AgentRoutingService::resolveAgentForLead()
  // Dispatch SendClientInvoiceJob (queue: medium)
  // Update status = sent (setelah job di-queue)

handleDeliveryUpdate(string $waMessageId, string $status): void
  // Update client_invoices.status berdasarkan WA status update
```

### PaymentProofRoutingService

```php
// Dipanggil dari MediaHandlerService (Phase 4) saat detect bukti bayar

route(Lead $lead, Message $message): void
  // Cari client_invoice dengan status = sent/delivered untuk lead ini
  // Jika ada: buat HandoffRequest (reason: payment_proof)
  //           attach media_url ke handoff summary
  //           Dispatch HandoffCreatedNotification
  // Jika tidak ada: abaikan (sudah di-handle generic media response)
```

---

## Jobs

### SendClientInvoiceJob (queue: medium)

```php
// Input: client_invoice_id
// 1. Load invoice + lead + agent
// 2. Jika ada intro_message: kirim text dulu via OutboundDispatchService
// 3. Kirim PDF/dokumen via OutboundDispatchService (type: document)
// 4. Update invoice: wa_message_id, sent_at, status = sent
// 5. Jika gagal: update status = draft kembali, log error
```

---

## Actions

### CreateClientInvoiceAction

Input DTO: `{ lead_id, items[], due_date, intro_message, notes }`
Validasi: lead harus milik tenant yang sama, items tidak boleh kosong.
Panggil ClientInvoiceService::createFromItems()

### UploadClientInvoiceAction

Input: `{ lead_id, file (PDF), due_date, intro_message }`
Validasi: file harus PDF, max 5MB.
Panggil ClientInvoiceService::attachUploadedPdf()

### SendClientInvoiceAction

Input: `{ invoice_id }`
Validasi: invoice harus draft, lead harus ada assigned agent yang connected.
Panggil ClientInvoiceDispatchService::dispatch()

---

## Routes

```php
// Vendor admin
GET  /leads/{lead}/invoices              → list invoice per lead
POST /leads/{lead}/invoices              → CreateClientInvoiceAction
POST /leads/{lead}/invoices/upload       → UploadClientInvoiceAction
POST /leads/{lead}/invoices/{id}/send    → SendClientInvoiceAction
GET  /leads/{lead}/invoices/{id}         → detail invoice

// Webhook (dari Baileys, untuk status update)
// Sudah di-handle di WebhookIngressService Phase 3
// message_status_update → ClientInvoiceDispatchService::handleDeliveryUpdate()
```

---

## Storage

```
storage/app/tenants/{tenant_id}/
  invoices/
    {invoice_id}.pdf       ← uploaded atau generated PDF
```

---

## Tests (Pest)

```
✓ ClientInvoiceService: invoice number unik per tenant
✓ ClientInvoiceService: invoice linked ke tenant + lead yang benar
✓ ClientInvoiceService: cross-tenant — admin tidak bisa akses invoice tenant lain
✓ SendClientInvoiceJob: dispatch ke queue medium
✓ SendClientInvoiceJob: status transition draft → sent benar
✓ PaymentProofRoutingService: ada invoice sent → buat handoff
✓ PaymentProofRoutingService: tidak ada invoice sent → tidak buat handoff
✓ CreateClientInvoiceAction: items kosong → validation error
✓ UploadClientInvoiceAction: file bukan PDF → validation error
```

---

## Setelah Selesai, Laporkan

1. Semua file yang dibuat
2. Flow invoice dari create sampai payment proof terdeteksi
3. Storage path yang dipakai
4. Queue yang dipakai per job
5. TODOs yang belum resolved
