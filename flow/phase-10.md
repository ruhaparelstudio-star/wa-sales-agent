# Phase 10 — Hardening, Tests, Production Deploy

## Final Audit: Tenant Isolation, N+1, Security, Indexes, VPS Deploy

> Pastikan CONTEXT.md sudah di-paste. Semua phase 01–09 harus selesai.
> Ini fase final sebelum production.

---

## Yang Dilakukan di Phase Ini

Bukan membangun fitur baru. Audit dan hardening semua yang sudah dibuat:

1. Tenant isolation audit
2. N+1 query audit dan fix
3. Index verification
4. Queue boundary audit
5. Security review
6. Test coverage pass
7. Production deploy checklist + scripts

---

## 1. Tenant Isolation Audit

Periksa setiap service dan controller:

```
Checklist per modul:
□ Semua query tenant-scoped ada .where('tenant_id', $tenantId)?
□ Semua mutasi validate ownership sebelum update/delete?
□ Tidak ada query yang bisa return data lintas tenant?
□ File storage: path sudah include tenant_id?
□ Cache keys: sudah include tenant_id?
□ Notification: hanya dikirim ke user yang benar tenant-nya?
```

Buat laporan: daftar titik yang berpotensi bocor + fix yang dilakukan.

---

## 2. N+1 Query Audit

Periksa titik-titik berikut:

```
□ Leads list: eager load whatsapp_agent, lead_profile
□ Conversation viewer: eager load messages.lead
□ Dashboard metrics: gunakan aggregate queries, bukan loop
□ Billing invoice list: eager load subscription, tenant
□ Notification list: minimal, hanya data yang ditampilkan
□ Agent list: tidak ada query per-agent dalam loop
```

Fix dengan eager loading. Gunakan `->with([...])` atau query scope.
Tambahkan `withCount()` untuk counters di list views.

---

## 3. Index Verification

Pastikan semua ini ada di migrations:

```
leads:                (tenant_id, phone_e164) UNIQUE, (tenant_id, status), (tenant_id, last_message_at)
conversations:        (tenant_id, lead_id), (tenant_id, status), (tenant_id, updated_at)
messages:             (conversation_id, created_at), (tenant_id, created_at), (lead_id, created_at)
whatsapp_agents:      (tenant_id, phone_number) UNIQUE, (tenant_id, status)
whatsapp_pairings:    (tenant_id, status), (pairing_token), (expires_at)
subscriptions:        (tenant_id, status), (tenant_id, ends_at), (ends_at)
billing_invoices:     (tenant_id, status), (tenant_id, due_date), (due_date)
knowledge_items:      (tenant_id, type, is_active)
booking_fields:       (tenant_id, template_id, sort_order)
client_invoices:      (tenant_id, lead_id, status), (tenant_id, due_date)
llm_usage_logs:       (tenant_id, created_at), (tenant_id, mode, created_at)
handoff_requests:     (tenant_id, status), (tenant_id, lead_id), (tenant_id, created_at)
notifications:        (notifiable_id, notifiable_type, read_at)
tenant_invitations:   (token) UNIQUE, (expires_at)
lead_memories:        (tenant_id, lead_id) — FK unique sudah cover ini
```

Buat laporan: index mana yang hilang, migration fix apa yang perlu ditambahkan.

---

## 4. Queue Boundary Audit

Verifikasi semua operasi berat sudah async:

```
Wajib via queue (verifikasi masing-masing):
□ Outbound WA send (OutboundDispatchService → SendOutboundMessageJob)
□ LLM inference (AgentOrchestrator → job)
□ Invoice send (ClientInvoiceDispatchService → SendClientInvoiceJob)
□ Conversation summary refresh (RefreshConversationSummaryJob)
□ Follow-up scheduling (FollowUpSchedulerJob)
□ Billing invoice generation (GenerateRenewalInvoicesJob)
□ Semua 7 notification dispatch (ShouldQueue)
□ Media download + storage (MediaHandlerService → job)
```

Buat laporan: ada yang sync padahal harusnya async?

---

## 5. Security Review

```
□ Webhook controller: validasi X-Baileys-Secret sebelum proses
□ Semua admin write endpoint: validasi tenant ownership
□ File upload: validasi mime type, max size, extension whitelist
□ Lead data: tidak ada endpoint yang expose data lead antar tenant
□ Super admin routes: middleware super_admin check di semua route
□ Billing proof upload: hanya vendor_admin tenant yang bersangkutan
□ Invoice: tidak bisa akses/kirim invoice milik tenant lain
□ Knowledge: tidak bisa baca knowledge tenant lain
□ Idempotency key di webhook: pastikan tidak bisa replay attack
```

---

## 6. Test Coverage Pass

Generate atau lengkapi tests yang belum ada:

```
Target minimum per modul:
□ Auth: login, logout, invitation flow, activation
□ Tenancy: isolation, resolver, guard
□ Subscription: status transitions, slot counting, enforcement
□ Billing: approval flow, proof upload, renewal generation
□ WhatsApp: pairing lifecycle, reconnect, duplicate reject, webhook ingress idempotency
□ Leads: create, stage advance, automation pause/resume
□ Conversations: open/resume, recent messages bounded
□ Messages: ingest text, ingest media, payment proof detection
□ AgentCore: context assembly, guardrails, follow-up policy, risk scoring
□ Knowledge: isolation, retrieval, candidate approval
□ Invoice: create, upload, send, status transitions, proof routing
□ Notifications: correct recipients, correct channels, no cross-tenant
□ Queue: jobs dispatch to correct queues
```

Run: `php artisan test` — semua harus green.

---

## 7. Production Deploy Checklist

Generate file `DEPLOY.md` di root project dengan langkah berikut:

### Server Requirements

```
OS: Ubuntu 22.04 LTS
PHP: 8.4+ dengan extensions: BCMath, Ctype, Fileinfo, JSON, Mbstring, OpenSSL, PDO, Tokenizer, XML, Redis, GD
Node.js: 20 LTS
MySQL: 8.0+
Redis: 7+
Nginx: latest stable
Supervisor: latest
```

### Deploy Steps

```bash
# 1. Clone project
git clone {repo} /var/www/html
cd /var/www/html

# 2. Install dependencies
composer install --no-dev --optimize-autoloader
npm ci --production  # untuk baileys-svc

# 3. Environment
cp .env.example .env
# Edit .env: DB, Redis, Mail, OpenAI key, Baileys secret, App URL

# 4. Generate app key
php artisan key:generate

# 5. Database
php artisan migrate --force

# 6. Storage
php artisan storage:link
mkdir -p storage/app/tenants
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# 7. Optimize
php artisan optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 8. Supervisor
cp deployment/supervisor/wedding-saas.conf /etc/supervisor/conf.d/
supervisorctl reread && supervisorctl update
supervisorctl start all

# 9. Nginx
cp deployment/nginx/production.conf /etc/nginx/sites-available/wedding-saas
ln -s /etc/nginx/sites-available/wedding-saas /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx

# 10. Baileys sidecar
cd baileys-svc && npm ci --production
pm2 start src/index.js --name baileys-svc
pm2 save && pm2 startup

# 11. Cron
crontab -e
# Tambahkan: * * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1

# 12. SSL (jika belum)
certbot --nginx -d yourdomain.com
```

### .env Production Checklist

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_HOST=127.0.0.1
DB_DATABASE=wedding_saas
DB_USERNAME=wedding_user
DB_PASSWORD=strong-password

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

QUEUE_CONNECTION=redis
HORIZON_ENABLED=true

OPENAI_API_KEY=sk-...

BAILEYS_BASE_URL=http://127.0.0.1:3001
BAILEYS_SECRET=very-long-random-secret

MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
# ... dll

SESSION_DRIVER=redis
CACHE_DRIVER=redis
```

### Nginx Production Config

Generate `deployment/nginx/production.conf`:

```nginx
server {
    listen 443 ssl;
    server_name yourdomain.com;
    root /var/www/html/public;

    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Baileys SSE — no buffering!
    location /baileys/ {
        proxy_pass http://127.0.0.1:3001/;
        proxy_http_version 1.1;
        proxy_set_header Connection '';
        proxy_buffering off;
        proxy_cache off;
        proxy_read_timeout 300s;
        chunked_transfer_encoding on;
    }

    # Horizon dashboard — protect!
    location /horizon {
        # Tambahkan IP whitelist atau basic auth
        allow 127.0.0.1;
        # allow your-office-ip;
        deny all;
        try_files $uri /index.php?$query_string;
    }
}

server {
    listen 80;
    server_name yourdomain.com;
    return 301 https://$server_name$request_uri;
}
```

---

## Output yang Dihasilkan Phase Ini

```
1. Laporan tenant isolation: titik yang ditemukan + fix yang dilakukan
2. Laporan N+1: query yang diperbaiki
3. Laporan index: index yang ditambahkan (migration files)
4. Laporan security: temuan + fix
5. Test results: semua green atau daftar yang masih failing
6. File: DEPLOY.md
7. File: deployment/supervisor/wedding-saas.conf
8. File: deployment/nginx/production.conf
```

---

## Setelah Selesai, Laporkan

1. Summary semua audit dengan temuan kritis
2. List composite indexes yang ditambahkan
3. List caches yang dipakai sistem
4. List unresolved risk items (jika ada)
5. Estimated production readiness (skala 1-10 + alasan)
