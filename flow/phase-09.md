# Phase 09 — Queues, Horizon, Scheduler, Notifications

## Queue Config, Scheduler Tasks, 7 Notification Types, Horizon Setup

> Pastikan CONTEXT.md sudah di-paste. Phase 01–08 harus selesai.

---

## Yang Dibangun di Phase Ini

1. Queue configuration (3 priority queues)
2. Laravel Horizon setup
3. Scheduler tasks
4. 7 Laravel Notification classes
5. Supervisor config untuk production
6. Idempotency untuk jobs kritis

---

## Queue Configuration

### config/queue.php — Connections

```php
// Redis connection dengan 3 queue names
'redis' => [
    'driver'     => 'redis',
    'connection' => 'default',
    'queue'      => env('QUEUE_NAME', 'default'),
    'retry_after' => 90,
    'block_for'   => null,
],
```

### Queue Names & Usage

```
high    → ProcessInboundMessageJob, SendOutboundMessageJob (realtime reply)
medium  → SendClientInvoiceJob, HandoffCreatedNotification, BillingAlertNotification
low     → GenerateRenewalInvoicesJob, RefreshConversationSummaryJob,
          FollowUpSchedulerJob, SnapshotDashboardMetricsJob,
          KnowledgeCandidateExtractionJob
```

---

## Horizon Configuration

### config/horizon.php

```php
'environments' => [
    'production' => [
        'supervisor-high' => [
            'connection' => 'redis',
            'queue'      => ['high'],
            'balance'    => 'auto',
            'processes'  => 4,
            'tries'      => 3,
            'timeout'    => 60,
        ],
        'supervisor-medium' => [
            'queue'    => ['medium'],
            'balance'  => 'simple',
            'processes'=> 2,
            'tries'    => 3,
            'timeout'  => 120,
        ],
        'supervisor-low' => [
            'queue'    => ['low'],
            'balance'  => 'simple',
            'processes'=> 2,
            'tries'    => 2,
            'timeout'  => 300,
        ],
    ],
    'local' => [
        'supervisor-local' => [
            'queue'    => ['high', 'medium', 'low'],
            'balance'  => 'simple',
            'processes'=> 3,
            'tries'    => 3,
        ],
    ],
],
```

---

## Scheduler Tasks

### app/Console/Kernel.php (atau routes/console.php di Laravel 13)

```php
// Harian jam 08:00 — generate renewal invoices
$schedule->job(GenerateRenewalInvoicesJob::class, 'low')->dailyAt('08:00');

// Harian jam 09:00 — cek dan kirim billing alerts
$schedule->job(SendBillingAlertsJob::class, 'medium')->dailyAt('09:00');

// Setiap 15 menit — follow-up spreader
$schedule->job(FollowUpSchedulerJob::class, 'low')->everyFifteenMinutes();

// Setiap 30 menit — snapshot dashboard metrics
$schedule->job(SnapshotDashboardMetricsJob::class, 'low')->everyThirtyMinutes();

// Cron entry (1 entry saja di server):
// * * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
```

---

## Jobs (yang belum dibuat di phase sebelumnya)

### SendBillingAlertsJob (queue: medium)

```php
// Ambil subscriptions yang:
//   ends_at = today + 7 hari → kirim BillingAlertNotification (H-7)
//   ends_at = today + 3 hari → H-3
//   ends_at = today + 1 hari → H-1
//   ends_at = today          → H-0
//   ends_at = yesterday      → H+1 (sudah expired)
// Untuk setiap tenant: kirim notif ke semua vendor_admin
// Idempotency: cache key "billing_alert:{subscription_id}:{days}" TTL 23 jam
//   → skip jika sudah terkirim hari ini
```

### FollowUpSchedulerJob (queue: low)

```php
// Ambil leads yang:
//   automation_paused = false
//   follow_up_count < 2
//   status NOT IN [closed_won, closed_lost, ready_for_human]
//   last_message_at >= 18 jam lalu (FU-1) atau fu1_sent_at >= 48 jam lalu (FU-2)
// Untuk setiap lead: cek GuardrailService
//   → jika lolos: dispatch SendFollowUpMessageJob
// Spread job untuk hindari burst: jangan dispatch semua sekaligus
//   → chunk per 10, dengan delay antar chunk
```

### SendFollowUpMessageJob (queue: low)

```php
// Generate follow-up message via AgentOrchestrator::generateFollowUp()
// Apply delay via DelayPolicyService
// OutboundDispatchService::send()
// FollowUpPolicyService::recordFollowUpSent()
```

### SnapshotDashboardMetricsJob (queue: low)

```php
// Hitung metrics per tenant: hot_leads_count, pending_handoffs_count, dll
// Simpan ke cache Redis dengan TTL 35 menit
// DashboardMetricsService mengambil dari cache ini
```

---

## 7 Notification Classes

Semua di `app/Modules/Dashboard/Notifications/`
Semua implement `ShouldQueue`, queue = 'medium' (kecuali yang low)

### 1. HandoffCreatedNotification

```php
// Penerima: TenantMembershipService::getAdminsForTenant($tenant) → semua vendor_admin
// Channel: database + mail
// Database data: { lead_name, reason, conversation_url, created_at }
// Mail subject: "[Wedding Agent] Lead {lead_name} butuh perhatian kamu"
// Mail body: nama lead, reason handoff, link ke conversation
```

### 2. BillingAlertNotification

```php
// Penerima: vendor_admin dari tenant
// Channel: database + mail
// Constructor param: Subscription $sub, int $daysRemaining
// Message dinamis berdasarkan daysRemaining:
//   7 → "Langganan berakhir 7 hari lagi"
//   3 → "Langganan berakhir 3 hari lagi — segera perpanjang"
//   1 → "Langganan berakhir besok!"
//   0 → "Langganan hari ini berakhir"
//   -1 → "Langganan sudah expired — agent dinonaktifkan sementara"
// Database data: { days_remaining, ends_at, billing_url }
```

### 3. AgentDisconnectedNotification

```php
// Penerima: vendor_admin dari tenant
// Channel: database + mail
// Data: nomor WA yang disconnect, waktu, alasan
// Mail subject: "[Wedding Agent] Nomor WA {phone} terputus"
// Database data: { phone_number, reason, reconnect_url, disconnected_at }
```

### 4. BillingPaymentReceivedNotification

```php
// Penerima: semua super admin (User::where('is_super_admin', true)->get())
// Channel: database + mail
// Data: tenant name, invoice number, amount, link approval
// Mail subject: "[Wedding Agent] Bukti bayar baru dari {tenant_name}"
```

### 5. BillingPaymentApprovedNotification

```php
// Penerima: vendor_admin dari tenant
// Channel: database + mail
// Data: invoice number, new subscription period (starts_at, ends_at)
// Mail subject: "[Wedding Agent] Pembayaran dikonfirmasi — Langganan aktif sampai {date}"
```

### 6. HotLeadAlertNotification

```php
// Penerima: vendor_admin dari tenant
// Channel: database ONLY (terlalu frequent untuk email)
// Queue: low
// Data: { lead_name, phone, stage, lead_url }
```

### 7. AgentSlotLimitNotification

```php
// Penerima: vendor_admin yang melakukan aksi (single user)
// Channel: database ONLY
// Queue: low
// Data: { plan_name, max_agents, current_connected, upgrade_url }
```

---

## Trigger Points (Dimana Notification Di-dispatch)

```
HandoffCreatedNotification      → HandoffRequestService::create()
BillingAlertNotification        → SendBillingAlertsJob
AgentDisconnectedNotification   → WhatsAppAgentService::handleAgentDisconnected()
BillingPaymentReceivedNotification → UploadBillingProofAction
BillingPaymentApprovedNotification → BillingApprovalService::approve()
HotLeadAlertNotification        → LeadStageService::advanceStage() jika HOT/READY_FOR_HUMAN
AgentSlotLimitNotification      → PairingService::initiatePairing() jika slot penuh
```

---

## Supervisor Config (Production VPS)

Generate file `/etc/supervisor/conf.d/wedding-saas.conf`:

```ini
[program:laravel-horizon]
process_name=%(program_name)s
command=php /var/www/html/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/horizon.log
stopwaitsecs=3600

[program:laravel-scheduler]
process_name=%(program_name)s
command=/bin/bash -c "while [ true ]; do php /var/www/html/artisan schedule:run; sleep 60; done"
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/scheduler.log
```

---

## Idempotency Keys

Implementasi untuk jobs yang perlu idempotency:

```php
// Helper: simpan ke Redis dengan TTL
Cache::remember("idem:{$key}", 86400, fn() => true)
// Cek sebelum proses: if (Cache::has("idem:{$key}")) return;

// Keys:
// Billing alert: "billing_alert:{subscription_id}:{days_remaining}"
// Invoice send:  "invoice_send:{invoice_id}"
// Webhook:       "webhook:{X-Idempotency-Key}"
```

---

## Mail Config (.env)

```env
# Dev
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_FROM_ADDRESS=noreply@wedding-agent.test
MAIL_FROM_NAME="Wedding Sales Agent"

# Production (ganti dengan Mailgun/SendGrid)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=postmaster@yourdomain.com
MAIL_PASSWORD=your-mailgun-key
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="Wedding Sales Agent"
```

---

## Tests (Pest)

```
✓ SendBillingAlertsJob: idempotency — tidak kirim dua kali dalam 24 jam
✓ SendBillingAlertsJob: H-7, H-3, H-1 masing-masing trigger notifikasi benar
✓ FollowUpSchedulerJob: lead yang automation_paused tidak di-follow-up
✓ FollowUpSchedulerJob: follow_up_count >= 2 tidak di-follow-up
✓ HandoffCreatedNotification: dikirim ke semua vendor_admin tenant, bukan tenant lain
✓ BillingAlertNotification: message sesuai hari yang tersisa
✓ HotLeadAlertNotification: hanya database, tidak email
✓ Jobs dispatch ke queue yang benar (high/medium/low)
```

---

## Setelah Selesai, Laporkan

1. Semua file yang dibuat
2. Queue priorities yang dikonfigurasi
3. Semua scheduler tasks dengan jadwalnya
4. 7 notification classes dan trigger masing-masing
5. Cara idempotency bekerja untuk billing alerts
6. TODOs yang belum resolved
