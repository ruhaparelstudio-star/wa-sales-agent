# Phase 02 — Subscription & Billing Core

## Plans, Subscriptions, Billing Invoices, Slot Enforcement

> Pastikan CONTEXT.md sudah di-paste. Phase 01 harus sudah selesai.

---

## Yang Dibangun di Phase Ini

1. Subscription plans (paket langganan)
2. Subscriptions (langganan per tenant)
3. Billing invoices (tagihan subscription, bukan client invoice)
4. Agent slot enforcement (disconnected tidak hitung slot)
5. Manual approval flow oleh super admin
6. Renewal generation job (auto-generate tagihan H-7)

---

## Migrations

### subscription_plans

```sql
id, name, slug (unique), max_agents (int),
monthly_token_soft_cap (bigint, default 1500000),
features (json, nullable), price (decimal 10,2),
is_active (bool, default true), sort_order (int, default 0),
created_at, updated_at
INDEX: (is_active, sort_order)
```

### subscriptions

```sql
id, tenant_id (FK), plan_id (FK),
status (enum: pending_payment|active|grace_period|expired|suspended),
starts_at (timestamp), ends_at (timestamp),
trial_ends_at (timestamp nullable),
grace_ends_at (timestamp nullable),
created_at, updated_at
INDEX: (tenant_id, status), INDEX: (tenant_id, ends_at), INDEX: (ends_at)
```

### billing_invoices

```sql
id, tenant_id (FK), subscription_id (FK nullable),
invoice_number (unique string), amount (decimal 10,2),
status (enum: unpaid|paid|cancelled),
due_date (date), period_start (date), period_end (date),
proof_path (varchar nullable), proof_uploaded_at (timestamp nullable),
approved_by (FK users nullable), approved_at (timestamp nullable),
notes (text nullable),
created_at, updated_at
INDEX: (tenant_id, status), INDEX: (tenant_id, due_date), INDEX: (due_date)
```

---

## Enums

```php
SubscriptionStatus: pending_payment | active | grace_period | expired | suspended
BillingInvoiceStatus: unpaid | paid | cancelled
```

---

## Models

```
SubscriptionPlan → hasMany(Subscription)
Subscription     → belongsTo(Tenant), belongsTo(SubscriptionPlan), hasMany(BillingInvoice)
BillingInvoice   → belongsTo(Tenant), belongsTo(Subscription), belongsTo(User 'approvedBy')
```

### Scopes & Helpers pada Subscription

```php
scopeActive(), scopeExpired(), scopeGrace()
isActive(): bool
isInGracePeriod(): bool
isExpired(): bool
daysUntilExpiry(): int
isTrialing(): bool
```

---

## Services

### PlanService (app/Modules/Subscription/Services/)

```php
getActivePlans(): Collection
getPlanById(int $id): SubscriptionPlan
getPlanFeatures(SubscriptionPlan $plan): array
```

### SubscriptionService (app/Modules/Subscription/Services/)

```php
getActiveSub(Tenant $tenant): ?Subscription
assignPlan(Tenant $tenant, SubscriptionPlan $plan, int $trialDays = 0): Subscription
renewSubscription(Subscription $sub): Subscription
refreshStatus(Subscription $sub): Subscription   // update status berdasarkan tanggal
```

### SubscriptionEnforcementService (app/Modules/Subscription/Services/)

```php
canAddAgent(Tenant $tenant): bool        // cek slot dan status aktif
assertCanSendOutbound(Tenant $tenant): void   // throw jika expired/suspended
getConnectedAgentCount(Tenant $tenant): int
```

### AgentSlotPolicyService (app/Modules/Subscription/Services/)

```php
// Hanya whatsapp_agents dengan status = connected yang dihitung
getUsedSlots(Tenant $tenant): int
getRemainingSlots(Tenant $tenant): int
isSlotAvailable(Tenant $tenant): bool
```

**Penting:** Disconnected agent TIDAK mengurangi slot. Hanya `status = connected` yang dihitung.

### BillingInvoiceService (app/Modules/Billing/Services/)

```php
generateForRenewal(Subscription $sub): BillingInvoice
getUnpaidInvoices(Tenant $tenant): Collection
uploadProof(BillingInvoice $invoice, UploadedFile $file): BillingInvoice
```

### BillingApprovalService (app/Modules/Billing/Services/)

```php
approve(BillingInvoice $invoice, User $approver): void
  // Set status = paid, approved_by, approved_at
  // Panggil SubscriptionService::renewSubscription()
  // Dispatch BillingPaymentApprovedNotification
reject(BillingInvoice $invoice, User $approver, string $reason): void
```

### RenewalGenerationService (app/Modules/Billing/Services/)

```php
generateUpcomingRenewals(): void
  // Ambil subscriptions yang ends_at antara sekarang dan 7 hari ke depan
  // Generate billing_invoice jika belum ada yang unpaid untuk periode itu
  // Dispatch BillingAlertNotification ke vendor admin
```

---

## Jobs

### GenerateRenewalInvoicesJob (app/Modules/Billing/Jobs/)

```php
// Queue: low
// Dipanggil oleh scheduler harian
// Panggil RenewalGenerationService::generateUpcomingRenewals()
```

---

## Actions

### AssignPlanAction (app/Modules/Subscription/Actions/)

Input: `{ tenant_id, plan_id, trial_days }`
Hanya bisa dilakukan super admin.
Steps: validate plan aktif → SubscriptionService::assignPlan()

### ApproveBillingPaymentAction (app/Modules/Billing/Actions/)

Input: `{ billing_invoice_id }`
Hanya bisa dilakukan super admin.
Steps: validate invoice unpaid → BillingApprovalService::approve()

### UploadBillingProofAction (app/Modules/Billing/Actions/)

Input: `{ billing_invoice_id, file }`
Dilakukan oleh vendor admin untuk tenantnya sendiri.
Steps: validate ownership → BillingInvoiceService::uploadProof()
→ Dispatch BillingPaymentReceivedNotification ke super admin

---

## Routes

```php
// Vendor admin
GET  /billing              → BillingController@index (plan aktif, invoice list)
POST /billing/{id}/proof   → UploadBillingProofAction

// Super admin
GET  /superadmin/billing
POST /superadmin/billing/{id}/approve  → ApproveBillingPaymentAction
POST /superadmin/subscriptions/assign  → AssignPlanAction
```

---

## Seeder (Opsional, jika diminta)

```php
// Seed 3 plan default
SubscriptionPlan::create(['name' => 'Starter', 'max_agents' => 1, 'price' => 299000])
SubscriptionPlan::create(['name' => 'Growth',  'max_agents' => 3, 'price' => 599000])
SubscriptionPlan::create(['name' => 'Pro',     'max_agents' => 5, 'price' => 999000])
```

---

## Tests (Pest)

```
✓ SubscriptionService: assign plan, refresh status (active/grace/expired)
✓ AgentSlotPolicyService: disconnected agent tidak hitung slot
✓ SubscriptionEnforcementService: expired tenant blocked dari add agent
✓ BillingApprovalService: approve trigger renewal + notifikasi
✓ RenewalGenerationService: tidak duplikat invoice jika sudah ada yang unpaid
✓ daysUntilExpiry() akurat untuk edge case hari ini expired
```

---

## Setelah Selesai, Laporkan

1. Semua file yang dibuat beserta path
2. Cara slot counting bekerja (hanya connected)
3. Semua indexes yang ditambahkan
4. TODOs yang belum resolved
