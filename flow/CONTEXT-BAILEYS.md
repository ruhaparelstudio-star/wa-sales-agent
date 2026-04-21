# CONTEXT-BAILEYS — WhatsApp Baileys Sidecar

## Paste ini sebelum phase-03.md

---

## Arsitektur Baileys Sidecar

Baileys berjalan sebagai **Node.js service terpisah** di samping Laravel.

```
Browser ←── SSE (QR stream) ──← Baileys Sidecar (Node.js :3001)
                                        ↕ REST API
Laravel App (PHP) ──────────────────────┘
       ↑
       └── Webhook POST dari Baileys (inbound events)
```

---

## Docker Services (Dev — Windows)

```yaml
services:
  app: # Laravel PHP-FPM
  baileys-svc: # Node.js Baileys (:3001)
  mysql: # MySQL 8
  redis: # Redis 7
  nginx: # Reverse proxy (:80)
  mailpit: # Dev email UI (:8025), SMTP (:1025)
```

Nginx proxy rules:

- `/` → Laravel (PHP-FPM)
- `/baileys/` → Baileys sidecar (proxy_pass, no buffering untuk SSE)

Tunnel untuk dev (WA butuh public URL):

- Cloudflare Tunnel: `cloudflared tunnel --url http://localhost:80`
- atau ngrok: `ngrok http 80`

---

## Baileys REST API Contract

Base URL (internal Docker): `http://baileys-svc:3001`
Auth header wajib: `X-Baileys-Secret: {BAILEYS_SECRET}`

| Method | Endpoint                 | Fungsi                                |
| ------ | ------------------------ | ------------------------------------- |
| POST   | `/agents/:id/start`      | Start socket, return SSE URL          |
| GET    | `/agents/:id/qr-stream`  | SSE stream QR ke browser              |
| POST   | `/agents/:id/cancel`     | Cancel pairing (admin keluar halaman) |
| POST   | `/agents/:id/disconnect` | Disconnect + hapus session            |
| POST   | `/agents/:id/send`       | Kirim pesan outbound                  |
| GET    | `/agents/:id/status`     | Cek status koneksi                    |

### POST /agents/:id/send — Payload

```json
{
  "to": "+628123456789",
  "type": "text",
  "content": "Pesan teks",
  "idempotency_key": "uuid-job-id"
}
```

### Response sukses

```json
{ "message_id": "3EB0...", "status": "sent", "timestamp": "ISO8601" }
```

---

## Webhook Events (Baileys → Laravel)

Baileys POST ke `{LARAVEL_APP_URL}/api/whatsapp/webhook`

Header wajib per request:

```
X-Baileys-Secret: {secret}
X-Idempotency-Key: {unique-event-id}
Content-Type: application/json
```

### event: message_received

```json
{
  "event": "message_received",
  "agent_id": "uuid",
  "data": {
    "wa_message_id": "3EB0...",
    "from": "+628123456789",
    "type": "text",
    "content": "Pesan dari lead",
    "timestamp": "ISO8601",
    "is_from_me": false
  }
}
```

### event: agent_connected

```json
{
  "event": "agent_connected",
  "agent_id": "uuid",
  "data": { "phone_number": "+628111000000", "connected_at": "ISO8601" }
}
```

### event: agent_disconnected

```json
{
  "event": "agent_disconnected",
  "agent_id": "uuid",
  "data": {
    "reason": "logout|connection_lost|kicked",
    "disconnected_at": "ISO8601"
  }
}
```

### event: message_status_update

```json
{
  "event": "message_status_update",
  "agent_id": "uuid",
  "data": { "wa_message_id": "3EB0...", "status": "sent|delivered|read|failed" }
}
```

---

## Laravel Provider Abstraction

```php
// app/Modules/WhatsApp/Contracts/WhatsAppProviderInterface.php
interface WhatsAppProviderInterface {
    public function startAgent(string $agentId): array;
    public function cancelPairing(string $agentId): void;
    public function disconnectAgent(string $agentId): void;
    public function sendMessage(string $agentId, string $to, string $content, string $idempotencyKey): array;
    public function getAgentStatus(string $agentId): array;
    public function getQrStreamUrl(string $agentId): string;
}
```

Implementasi: `BaileysProvider` yang HTTP call ke sidecar.

---

## QR Pairing Rules (Dikunci)

```
1. Admin klik "Tambah Agent"
   → Cek slot: connected_agents < plan.max_agents
   → Buat whatsapp_pairings record (status: pending)
   → POST /agents/:id/start ke Baileys
   → Return SSE URL ke frontend

2. Browser connect ke SSE /baileys/agents/:id/qr-stream
   → QR tampil sebagai base64 image di modal
   → QR auto-rotate tiap ~20 detik (dari Baileys)

3. Admin keluar halaman / tutup modal
   → Frontend POST /api/whatsapp/agents/:id/cancel-pairing
   → Laravel POST /agents/:id/cancel ke Baileys
   → whatsapp_pairings.status = cancelled

4. QR di-scan:
   → Baileys POST webhook agent_connected ke Laravel
   → whatsapp_agents.status = connected
   → whatsapp_pairings.status = completed
   → Slot terhitung

5. Reconnect (nomor pernah disconnect):
   → Pakai whatsapp_agents record yang ada (jangan buat baru)
   → Update status ke connected
   → Slot terhitung kembali

6. Nomor sudah connected di-scan lagi → REJECT
```

---

## Session Management

- Session Baileys disimpan di `./baileys-sessions/auth_info_{agent_id}/`
- Volume di-mount dari host → persist restart container
- Jika folder ada → reconnect otomatis tanpa QR baru
- Jika folder tidak ada → QR pairing baru

---

## Media Handling di Webhook

Untuk inbound message tipe non-text:

```json
{
  "event": "message_received",
  "agent_id": "uuid",
  "data": {
    "wa_message_id": "3EB0...",
    "from": "+628...",
    "type": "image",
    "content": null,
    "caption": "ini foto venue",
    "media_url": "https://...",
    "media_mime": "image/jpeg",
    "timestamp": "ISO8601"
  }
}
```

Laravel menyimpan media ke `storage/app/tenants/{tenant_id}/media/{year}/{month}/{wa_message_id}.{ext}`

AI tidak memproses media — hanya teks yang masuk ke LLM.

---

## ENV Variables

```env
# Laravel .env
BAILEYS_BASE_URL=http://baileys-svc:3001
BAILEYS_SECRET=ganti-dengan-secret-aman

# Baileys .env (baileys-svc/)
LARAVEL_WEBHOOK_URL=https://your-tunnel.trycloudflare.com/api/whatsapp/webhook
BAILEYS_SECRET=ganti-dengan-secret-aman
PORT=3001
```
