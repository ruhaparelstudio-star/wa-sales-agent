# Phase 03 — WhatsApp Agent + Baileys Sidecar

## QR Pairing, Agent State, Webhook Ingress, Outbound Queue

> Paste CONTEXT.md + CONTEXT-BAILEYS.md sebelum ini. Phase 01 & 02 harus selesai.

---

## Yang Dibangun di Phase Ini

### A — Baileys Sidecar (Node.js service)

Service Node.js terpisah yang mengelola koneksi WhatsApp via Baileys.

### B — Laravel WhatsApp Module

Migrations, models, services, webhook handler, outbound dispatcher.

---

## BAGIAN A — Baileys Sidecar

Buat folder `baileys-svc/` di root project dengan struktur:

```
baileys-svc/
  src/
    index.js          ← Entry point, Fastify app
    agentManager.js   ← Kelola multiple socket instances
    webhookSender.js  ← POST events ke Laravel
    qrStreamer.js     ← SSE untuk QR ke browser
  sessions/           ← Session storage (mounted volume)
  .env.example
  Dockerfile
  package.json
```

### Fungsi Utama agentManager.js

```javascript
// Map: agentId → socket instance
const sockets = new Map();

startAgent(agentId);
// Baca session dari ./sessions/auth_info_{agentId}/
// Jika ada: reconnect (tidak perlu QR)
// Jika tidak ada: generate QR, stream via SSE
// Event handlers: connection.update, messages.upsert, message-receipt

cancelAgent(agentId);
// Stop socket tanpa hapus session
// Kirim event session_cancelled ke SSE subscribers

disconnectAgent(agentId);
// Logout socket, hapus session folder
// Kirim webhook agent_disconnected ke Laravel

sendMessage(agentId, to, content, type, idempotencyKey);
// Kirim via socket, return message ID
```

### REST Endpoints (Fastify)

```javascript
POST /agents/:id/start      → agentManager.startAgent()
GET  /agents/:id/qr-stream  → SSE (Content-Type: text/event-stream)
POST /agents/:id/cancel     → agentManager.cancelAgent()
POST /agents/:id/disconnect → agentManager.disconnectAgent()
POST /agents/:id/send       → agentManager.sendMessage()
GET  /agents/:id/status     → return current socket status
```

Middleware: validasi `X-Baileys-Secret` header di semua request.

### Webhook ke Laravel

```javascript
// webhookSender.js
async function sendWebhook(event, agentId, data) {
  await fetch(process.env.LARAVEL_WEBHOOK_URL, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-Baileys-Secret": process.env.BAILEYS_SECRET,
      "X-Idempotency-Key": generateUUID(),
    },
    body: JSON.stringify({ event, agent_id: agentId, data }),
  });
}
```

### Dockerfile (baileys-svc/Dockerfile)

```dockerfile
FROM node:20-alpine
WORKDIR /app
COPY package*.json ./
RUN npm ci --production
COPY src/ ./src/
EXPOSE 3001
CMD ["node", "src/index.js"]
```

---

## BAGIAN B — Laravel WhatsApp Module

### Migrations

#### whatsapp_agents

```sql
id (uuid recommended), tenant_id (FK), phone_number (varchar 20),
display_name (varchar nullable), status (enum: pending|connected|disconnected),
is_default (bool, default false),
last_connected_at (timestamp nullable), last_disconnected_at (timestamp nullable),
created_at, updated_at
UNIQUE: (tenant_id, phone_number)
INDEX: (tenant_id, status), INDEX: (tenant_id, is_default)
```

#### whatsapp_pairings

```sql
id (uuid), tenant_id (FK), whatsapp_agent_id (FK nullable),
status (enum: pending|completed|cancelled|expired),
pairing_token (unique varchar 64),
expires_at (timestamp nullable),
completed_at (timestamp nullable), cancelled_at (timestamp nullable),
created_at, updated_at
INDEX: (tenant_id, status), INDEX: (pairing_token), INDEX: (expires_at)
```

### Enums

```php
AgentStatus: pending | connected | disconnected
PairingStatus: pending | completed | cancelled | expired
```

### Models

```
WhatsAppAgent  → belongsTo(Tenant), hasMany(WhatsAppPairing)
               scope: connected(), disconnected(), forTenant($tenantId)
WhatsAppPairing → belongsTo(Tenant), belongsTo(WhatsAppAgent)
               scope: pending(), active()
```

### Provider Interface & Implementation

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

// app/Modules/WhatsApp/Services/BaileysProvider.php
// Implementasi yang HTTP call ke baileys-svc
// Inject via service container binding di AppServiceProvider
```

### Services

#### WhatsAppAgentService

```php
getAgentsForTenant(Tenant $tenant): Collection
getDefaultAgent(Tenant $tenant): ?WhatsAppAgent
setDefaultAgent(Tenant $tenant, WhatsAppAgent $agent): void
handleAgentConnected(string $agentId, string $phoneNumber): void
  // Update status=connected, last_connected_at
  // Update pairing status=completed
handleAgentDisconnected(string $agentId, string $reason): void
  // Update status=disconnected, last_disconnected_at
  // Dispatch AgentDisconnectedNotification
```

#### PairingService

```php
initiatePairing(Tenant $tenant): WhatsAppPairing
  // Cek slot via AgentSlotPolicyService::isSlotAvailable()
  // Buat pairing record (status: pending)
  // POST ke Baileys /agents/:id/start
  // Return pairing dengan qr_stream_url

cancelPairing(WhatsAppPairing $pairing): void
  // POST ke Baileys /agents/:id/cancel
  // Update status=cancelled

completePairing(string $agentId, string $phoneNumber): void
  // Panggil WhatsAppAgentService::handleAgentConnected()

attemptReconnect(Tenant $tenant, WhatsAppAgent $agent): void
  // Cek: agent.status harus disconnected
  // Cek slot tersedia
  // POST ke Baileys /agents/:id/start
  // Buat pairing baru untuk tracking
```

#### WebhookIngressService

```php
handle(array $payload, string $idempotencyKey): void
  // Cek idempotency key di Redis/cache — skip jika sudah diproses
  // Route berdasarkan payload['event']:
  //   message_received    → dispatch ProcessInboundMessageJob
  //   agent_connected     → WhatsAppAgentService::handleAgentConnected()
  //   agent_disconnected  → WhatsAppAgentService::handleAgentDisconnected()
  //   message_status_update → update message status di DB
  // Simpan idempotency key setelah berhasil (TTL 24 jam)
```

#### OutboundDispatchService

```php
send(WhatsAppAgent $agent, string $to, string $content, string $idempotencyKey): void
  // POST ke Baileys /agents/:id/send
  // Handle response, update outbound_message_queue status

queueSend(WhatsAppAgent $agent, string $to, string $content, string $queue = 'high'): void
  // Dispatch SendOutboundMessageJob dengan priority queue yang tepat
```

#### AgentRoutingService

```php
resolveAgentForLead(Tenant $tenant, Lead $lead): WhatsAppAgent
  // Jika lead.assigned_whatsapp_agent_id ada dan agent connected → return itu
  // Jika tidak → return default agent yang connected
  // Jika tidak ada default → ambil agent connected pertama

resolveAgentByPhone(string $agentPhone): ?WhatsAppAgent
  // Untuk webhook ingress: map nomor penerima ke whatsapp_agents record
```

### Webhook Controller

```php
// app/Modules/WhatsApp/Http/Controllers/WebhookController.php
// Route: POST /api/whatsapp/webhook (NO auth middleware, tapi validasi secret)

public function handle(Request $request): JsonResponse {
    // 1. Validate X-Baileys-Secret header
    // 2. Ambil X-Idempotency-Key header
    // 3. Inject ke WebhookIngressService::handle()
    // 4. Return 200 segera (jangan block untuk processing)
}
```

### Jobs

#### ProcessInboundMessageJob (queue: high)

```php
// Dipanggil dari WebhookIngressService setelah message_received
// Input: agent_id, from (phone), type, content, wa_message_id, timestamp
// Steps:
// 1. Resolve tenant dari agent
// 2. Find/create Lead by phone
// 3. Find/open Conversation
// 4. Store Message
// 5. Jika type = text → dispatch RunAgentCoreJob
// 6. Jika type != text → dispatch HandleMediaMessageJob
```

#### SendOutboundMessageJob (queue: high/medium/low sesuai context)

```php
// Input: agent_id, to, content, idempotency_key, delay_seconds
// Sleep(delay_seconds) dulu → OutboundDispatchService::send()
// Update outbound_message_queue status
```

### docker-compose.yml (root project)

Generate file lengkap dengan semua services:

```yaml
version: "3.9"
services:
  app:
    build: .
    volumes: [".:/var/www/html"]
    environment:
      BAILEYS_BASE_URL: http://baileys-svc:3001
      BAILEYS_SECRET: ${BAILEYS_SECRET}
    depends_on: [mysql, redis, baileys-svc]
    networks: [internal]

  baileys-svc:
    build: ./baileys-svc
    ports: ["3001:3001"]
    volumes: ["./baileys-sessions:/app/sessions"]
    environment:
      LARAVEL_WEBHOOK_URL: ${LARAVEL_WEBHOOK_URL}
      BAILEYS_SECRET: ${BAILEYS_SECRET}
    networks: [internal]

  nginx:
    image: nginx:alpine
    ports: ["80:80"]
    volumes: ["./nginx/dev.conf:/etc/nginx/conf.d/default.conf"]
    depends_on: [app, baileys-svc]
    networks: [internal]

  mysql:
    image: mysql:8
    environment: { MYSQL_DATABASE: wedding_saas, MYSQL_ROOT_PASSWORD: secret }
    volumes: [mysql-data:/var/lib/mysql]
    networks: [internal]

  redis:
    image: redis:7-alpine
    networks: [internal]

  mailpit:
    image: axllent/mailpit
    ports: ["8025:8025", "1025:1025"]
    networks: [internal]

volumes: { mysql-data: }
networks: { internal: }
```

### nginx/dev.conf

Generate file Nginx untuk Laravel (PHP-FPM) + Baileys SSE proxy (no-buffer).

---

## Tests (Pest)

```
✓ PairingService: slot penuh → reject initiate
✓ PairingService: cancel pairing → status cancelled
✓ WhatsAppAgentService: connected → status update benar
✓ WhatsAppAgentService: disconnected agent tidak hitung slot
✓ WebhookIngressService: idempotency key mencegah duplikat
✓ WebhookIngressService: event routing benar per tipe
✓ AgentRoutingService: return assigned agent jika connected
✓ AgentRoutingService: fallback ke default jika assigned disconnect
✓ Reconnect: nomor disconnected bisa reconnect, slot terhitung kembali
✓ Duplicate reject: nomor connected tidak bisa re-pair
```

---

## Setelah Selesai, Laporkan

1. Semua file yang dibuat (Laravel + baileys-svc)
2. docker-compose.yml dan nginx config yang dihasilkan
3. ENV variables yang dibutuhkan
4. Cara jalankan dev environment
5. TODOs yang belum resolved
