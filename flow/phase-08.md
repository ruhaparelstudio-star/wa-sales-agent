# Phase 08 — Dashboard & Livewire Pages

## Semua Halaman Admin: Vendor + Super Admin

> Pastikan CONTEXT.md sudah di-paste. Phase 01–07 harus selesai.

---

## Yang Dibangun di Phase Ini

1. Layout utama (sidebar, header, notification bell)
2. Vendor dashboard
3. WhatsApp Agents page (dengan QR modal SSE)
4. Billing page
5. Leads list + Lead detail + Conversation viewer
6. Knowledge page
7. Booking schema page
8. Invoice page
9. Super Admin panel
10. Notification bell (Livewire)

---

## Rules

- Semua list: Livewire pagination (tidak reload full page)
- Business logic: di service/view model, TIDAK di Livewire component
- Livewire component: hanya orchestration + state UI
- Tidak ada raw query di controller atau Livewire component
- Semua halaman harus cek: subscription masih aktif (banner jika tidak)

---

## Layout & Navigation

### Main Layout (resources/views/layouts/app.blade.php)

- Sidebar: Dashboard, Leads, WhatsApp Agents, Knowledge, Booking, Invoices, Billing
- Header: Nama tenant, user info, notification bell
- Subscription alert banner (jika mendekati expired atau sudah expired)
- Content area

### Subscription Alert Banner Component

```php
// Tampil otomatis jika:
// daysUntilExpiry() <= 7 → warning
// isInGracePeriod() → danger
// isExpired() → critical (outbound disabled)
```

---

## DashboardMetricsService

```php
// app/Modules/Dashboard/Services/DashboardMetricsService.php

getMetrics(Tenant $tenant): array
  // Return:
  // hot_leads_count: jumlah lead HOT + READY_FOR_HUMAN
  // pending_handoffs_count: HandoffRequest pending
  // connected_agents_count: WhatsAppAgent connected
  // agent_slots_used / agent_slots_max
  // subscription_days_remaining
  // unpaid_billing_count
  // total_leads_today
  // Cache: TTL 5 menit per tenant
```

---

## Halaman & Livewire Components

### 1. Vendor Dashboard

**Route:** `GET /dashboard`
**Livewire:** `Dashboard\VendorDashboard`

```
Metric cards: Hot Leads, Pending Handoffs, Agent Slots, Days Remaining
Table: 5 Hot Leads terbaru (link ke detail)
Table: 5 Pending Handoffs terbaru
Alert: Unpaid billing invoice
```

---

### 2. WhatsApp Agents Page

**Route:** `GET /whatsapp-agents`
**Livewire:** `WhatsApp\AgentList`

```
List agents: nomor, status badge, last connected, is_default toggle
Actions per agent: Set Default, Disconnect, Reconnect
Button: Tambah Agent → buka QR Modal
```

**QR Modal Component:** `WhatsApp\QrPairingModal`

```php
// State: pairingId, qrImageData, status (waiting/scanning/connected/cancelled)
// Saat modal dibuka: POST /api/whatsapp/pairing/initiate → dapat SSE URL
// Connect ke SSE via Alpine.js EventSource
// SSE events: qr_updated → update image, agent_connected → tutup modal + refresh list
// Saat modal ditutup: POST /api/whatsapp/pairing/{id}/cancel
```

**Alpine.js snippet untuk SSE di modal:**

```javascript
// Di x-data atau dalam komponen Blade:
const es = new EventSource(sseUrl);
es.addEventListener("qr_updated", (e) => {
  qrData = JSON.parse(e.data).qr_base64;
});
es.addEventListener("agent_connected", (e) => {
  closeModal();
  refreshAgentList();
});
es.addEventListener("session_cancelled", () => {
  es.close();
  showCancelledState();
});
// Cleanup saat modal ditutup: es.close()
```

---

### 3. Billing Page

**Route:** `GET /billing`
**Livewire:** `Billing\BillingPage`

```
Card: Plan aktif, masa aktif, harga
Card: Agent slots (used / max)
Alert: Billing invoice yang unpaid (dengan due date)
Table: History billing invoices (paginated)
Form: Upload bukti bayar (per invoice)
```

---

### 4. Leads List

**Route:** `GET /leads`
**Livewire:** `Leads\LeadList`

```
Filter: Status (tab atau dropdown), tanggal, search nama/nomor
Table: Nama, nomor, status badge, stage, last message, agent, actions
Actions: Lihat Detail, Pause/Resume Automation
Pagination: Livewire paginated
```

---

### 5. Lead Detail

**Route:** `GET /leads/{lead}`
**Livewire:** `Leads\LeadDetail`

```
Header: Nama, nomor, status, stage, assigned agent
Tabs:
  [Profil] → lead profile + booking data (inquiry form)
  [Percakapan] → conversation viewer
  [Handoffs] → list handoff requests
  [Invoices] → list + create/upload invoice

[Tab Percakapan] — ConversationViewer component:
  - Messages paginated (oldest first, atau infinite scroll ke atas)
  - Bubble: AI (biru), Human (abu), Lead (putih)
  - Media: show thumbnail jika image, show filename jika dokumen
  - Tombol: Takeover (mark human_takeover), Selesaikan Handoff

[Tab Invoices]:
  - List invoice per lead
  - Tombol Buat Invoice → form line items
  - Tombol Upload Invoice → upload PDF
  - Tombol Kirim → SendClientInvoiceAction
```

---

### 6. Knowledge Page

**Route:** `GET /knowledge`
**Livewire:** `Knowledge\KnowledgePage`

```
Tab: Knowledge Items | Candidates
[Knowledge Items]:
  - Filter by type
  - Table: judul, tipe, status, actions (edit, toggle aktif, hapus)
  - Button: Tambah Knowledge → form modal

[Candidates]:
  - Table: proposed title, tipe, source, actions (Approve, Reject)
```

---

### 7. Booking Schema Page

**Route:** `GET /booking-schema`
**Livewire:** `Booking\SchemaPage`

```
Tab: Inquiry Form | Booking Form
Per tab:
  - List fields dengan sort_order (drag-drop optional, atau up/down button)
  - Field: label, type, required toggle
  - Tambah Field button → form inline
  - Edit / Hapus per field
```

---

### 8. Invoice Page

**Route:** `GET /invoices`
**Livewire:** `Invoice\InvoiceList`

```
Filter: Status, lead search, date range
Table: Invoice number, lead, amount, status, due date, actions
Actions: Lihat Detail, Kirim (jika draft), Tandai Paid
```

---

### 9. Notification Bell

**Livewire Component:** `Dashboard\NotificationBell`

```php
// app/Modules/Dashboard/Http/Livewire/NotificationBell.php

// State: unreadCount, notifications (5 terbaru)
// Poll: setiap 30 detik (wire:poll.30s)
// Render: bell icon dengan badge jika unreadCount > 0
// Dropdown: list notifications dengan timestamp relatif, link ke resource
// Actions: markAsRead($id), markAllAsRead()
```

---

### 10. Super Admin Panel

**Route:** `GET /superadmin/*` (middleware: super_admin)

```
/superadmin/tenants          → list semua tenant + plan + status
/superadmin/tenants/create   → form buat tenant baru
/superadmin/tenants/{id}     → detail + edit plan + suspend

/superadmin/billing          → list billing invoices semua tenant yang unpaid
/superadmin/billing/{id}/approve → ApproveBillingPaymentAction

/superadmin/usage            → LLM usage per tenant (group by tenant, bulan ini)
```

---

## ViewModels

```php
// app/Modules/Dashboard/ViewModels/LeadDetailViewModel.php
// Kompilasi: lead + profile + active conversation + pending handoffs + invoices

// app/Modules/Dashboard/ViewModels/DashboardViewModel.php
// Kompilasi: metrics + alerts + hot leads + pending handoffs
```

---

## Tests (Pest)

```
✓ VendorDashboard: hanya tampil data tenant sendiri
✓ LeadList: filter status bekerja, pagination benar
✓ LeadDetail: 404 jika lead bukan milik tenant
✓ QrPairingModal: cancel pairing saat modal ditutup
✓ BillingPage: invoice list hanya milik tenant sendiri
✓ NotificationBell: unread count benar
✓ SuperAdmin: vendor admin tidak bisa akses /superadmin/*
```

---

## Setelah Selesai, Laporkan

1. Semua file Blade, Livewire, Controller yang dibuat
2. Cara QR SSE bekerja di modal (Alpine.js + SSE)
3. Cara notification bell poll data
4. TODOs yang belum resolved (terutama drag-drop schema jika di-skip)
