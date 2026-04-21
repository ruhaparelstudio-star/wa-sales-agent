# Sales Agent WA

Local development untuk project ini wajib lewat Docker karena stack target-nya adalah Laravel 13 + PHP 8.4, MySQL 8, Redis 7, Nginx, Mailpit, dan sidecar `baileys-svc`.

## Stack Local

- App Laravel: PHP-FPM 8.4
- Web server: Nginx
- Database: MySQL 8
- Cache/Queue: Redis 7
- Queue monitor: Horizon
- Mail catcher: Mailpit
- WhatsApp sidecar: Baileys on Node.js 20

App lokal dibuka di `http://localhost:8080`.

## Prasyarat

- Docker Desktop aktif
- Docker Compose v2 aktif
- Port `8080`, `3001`, `3307`, `6380`, `8025`, dan `1025` tidak dipakai service lain

Catatan:
PHP host lokal tidak dipakai untuk menjalankan aplikasi ini. Jika PHP host masih versi lama, itu aman selama Docker yang dipakai untuk run project.

## Setup Pertama Kali

1. Copy environment file jika belum ada:

```powershell
Copy-Item .env.example .env
```

2. Build dan nyalakan semua service:

```powershell
docker compose up -d --build
```

3. Install dependency PHP di container app:

```powershell
docker compose exec app composer install
```

4. Generate app key:

```powershell
docker compose exec app php artisan key:generate
```

5. Jalankan migration dan seeder:

```powershell
docker compose exec app php artisan migrate --seed
```

6. Install dependency frontend lalu build asset:

```powershell
npm install
npm run build
```

## Service URLs

- App: `http://localhost:8080`
- Mailpit: `http://localhost:8025`
- Baileys sidecar: `http://localhost:3001`
- MySQL host access: `127.0.0.1:3307`
- Redis host access: `127.0.0.1:6380`

## Command Harian

Menyalakan stack:

```powershell
docker compose up -d
```

Mematikan stack:

```powershell
docker compose down
```

Melihat log Laravel:

```powershell
docker compose logs -f app
```

Melihat log Baileys:

```powershell
docker compose logs -f baileys-svc
```

Menjalankan test:

```powershell
docker compose exec app php artisan test
```

Menjalankan command artisan:

```powershell
docker compose exec app php artisan <command>
```

## Konfigurasi Penting

- `APP_URL=http://localhost:8080`
- `BAILEYS_BASE_URL=http://baileys-svc:3001`
- `LARAVEL_WEBHOOK_URL=http://nginx/api/whatsapp/webhook`

`LARAVEL_WEBHOOK_URL` sengaja diarahkan ke service `nginx` di network Docker supaya webhook dari sidecar bisa masuk ke Laravel tanpa butuh tunnel eksternal saat development lokal.

## Troubleshooting

Jika `docker compose` gagal connect ke daemon di Windows, jalankan terminal sebagai Administrator lalu ulangi command.

Jika halaman tampil tanpa styling:

```powershell
npm install
npm run build
```

Jika migration gagal karena database belum siap:

```powershell
docker compose ps
docker compose exec app php artisan migrate --seed
```

Jika butuh reset database lokal:

```powershell
docker compose exec app php artisan migrate:fresh --seed
```
