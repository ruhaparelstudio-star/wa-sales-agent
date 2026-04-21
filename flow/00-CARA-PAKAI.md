# Panduan Penggunaan — Wedding Sales Agent SaaS

## Cara Kerja dengan Claude Code

---

## Prinsip Utama

**Satu phase = satu sesi Claude Code.**
Jangan gabung dua phase dalam satu prompt. Konteks akan overflow dan hasilnya tidak konsisten.

**Urutan file yang dibuka Claude Code per sesi:**

1. `CONTEXT.md` → wajib dibuka PERTAMA di setiap sesi baru
2. File phase yang sedang dikerjakan (misal `phase-01.md`)
3. File referensi tambahan jika dibutuhkan phase itu

---

## Struktur File

```
00-CARA-PAKAI.md          ← Panduan ini
CONTEXT.md                ← Konteks global (buka PERTAMA tiap sesi)
CONTEXT-LLM.md            ← Konteks khusus Agent Core & LLM
CONTEXT-BAILEYS.md        ← Konteks khusus Baileys sidecar

phase-01.md               ← Foundation: tenancy, auth, onboarding
phase-02.md               ← Subscription & billing core
phase-03.md               ← WhatsApp agent + Baileys sidecar
phase-04.md               ← Leads, conversations, messages, media
phase-05.md               ← Knowledge & booking schema
phase-06.md               ← Agent Core (LLM orchestration)
phase-07.md               ← Client invoice workflow
phase-08.md               ← Dashboard & Livewire pages
phase-09.md               ← Queues, Horizon, scheduler, notifications
phase-10.md               ← Hardening, tests, production deploy
```

---

## Cara Pakai Step-by-Step

### Langkah 1 — Setup Awal (Sekali Saja)

Buka Claude Code di terminal project kamu:

```bash
cd /path/to/your/project
claude
```

Lalu copy-paste isi `CONTEXT.md` sebagai pesan pertama. Tunggu Claude Code acknowledge.

---

### Langkah 2 — Jalankan Phase 1

Copy-paste isi `phase-01.md` ke Claude Code.

Setelah Claude Code selesai generate, **selalu tanya ini sebelum lanjut:**

```
Sebelum kita lanjut, tolong konfirmasi:
1. List semua file yang baru dibuat
2. Apakah semua query sudah scope tenant_id?
3. Apakah semua indexes sudah ditambahkan?
4. Ada TODO yang belum resolved?
```

---

### Langkah 3 — Lanjut ke Phase Berikutnya

Buka sesi Claude Code baru atau lanjut di sesi yang sama (jika konteks masih segar).

Untuk phase yang punya file context tambahan, copy context-nya dulu sebelum phase prompt:

- **Phase 3** → copy `CONTEXT-BAILEYS.md` dulu, baru `phase-03.md`
- **Phase 6** → copy `CONTEXT-LLM.md` dulu, baru `phase-06.md`

---

### Langkah 4 — Verifikasi Setiap Phase

Setelah setiap phase selesai, jalankan verifikasi ini:

```bash
# Di terminal (bukan Claude Code)
php artisan test --filter=<ModuleName>
php artisan migrate:status
```

---

## Peta Phase → File Context

| Phase | File Prompt   | Context Tambahan                    | Estimasi Waktu |
| ----- | ------------- | ----------------------------------- | -------------- |
| 1     | `phase-01.md` | `CONTEXT.md`                        | 15–20 menit    |
| 2     | `phase-02.md` | `CONTEXT.md`                        | 15–20 menit    |
| 3     | `phase-03.md` | `CONTEXT.md` + `CONTEXT-BAILEYS.md` | 25–35 menit    |
| 4     | `phase-04.md` | `CONTEXT.md`                        | 20–25 menit    |
| 5     | `phase-05.md` | `CONTEXT.md`                        | 15–20 menit    |
| 6     | `phase-06.md` | `CONTEXT.md` + `CONTEXT-LLM.md`     | 30–40 menit    |
| 7     | `phase-07.md` | `CONTEXT.md`                        | 15–20 menit    |
| 8     | `phase-08.md` | `CONTEXT.md`                        | 30–40 menit    |
| 9     | `phase-09.md` | `CONTEXT.md`                        | 20–25 menit    |
| 10    | `phase-10.md` | `CONTEXT.md`                        | 20–25 menit    |

---

## Tips Efisiensi Token

1. **Jangan tempel semua file sekaligus** — Claude Code punya context window terbatas. Satu phase per sesi.
2. **Gunakan `CONTEXT.md` sebagai anchor** — File ini adalah "memori" yang selalu dibawa tiap sesi baru.
3. **Kalau sesi terasa mulai lambat atau jawaban melenceng**, buka sesi baru dan paste ulang `CONTEXT.md`.
4. **Setelah phase 3 selesai**, simpan `docker-compose.yml` dan `nginx/dev.conf` yang dihasilkan — jangan hilang.
5. **Phase 6 paling berat** — siapkan sesi khusus, jangan gabung dengan phase lain.

---

## Jika Claude Code Keluar Konteks

Tanda-tanda Claude Code keluar konteks:

- Menggunakan nama tabel yang berbeda dari blueprint
- Tidak menambahkan `tenant_id` pada tabel baru
- Membuat helper/service global yang tidak ada di service map
- Menggunakan model LLM selain `gpt-4.1-mini`

**Cara fix:** Paste ulang bagian relevan dari `CONTEXT.md` dan tambahkan:

```
Ingat: kamu sedang membangun Wedding Sales Agent SaaS sesuai blueprint yang sudah dikunci.
Ikuti service map, folder structure, dan rules yang sudah ditetapkan.
Jangan buat service baru yang tidak ada di service map tanpa konfirmasi.
```

---

## Checklist Sebelum Production

Setelah Phase 10 selesai, pastikan ini semua done:

- [ ] Semua migrations sudah jalan di staging
- [ ] `php artisan test` green semua
- [ ] `.env.production` sudah diisi
- [ ] Supervisor config sudah di-copy ke server
- [ ] Nginx config sudah benar (Laravel + Baileys proxy)
- [ ] `php artisan optimize` sudah dijalankan
- [ ] Cloudflare Tunnel atau domain production sudah di-set
- [ ] Mailgun/SMTP production sudah dikonfigurasi
- [ ] Baileys sessions directory sudah ada dan writable
- [ ] Horizon dashboard sudah diproteksi

---

## Kontak Darurat (Jika Stuck)

Jika ada yang tidak jalan, tanyakan ke Claude dengan format:

```
Konteks: Wedding Sales Agent SaaS, Laravel 13 modular monolith
Phase yang sedang dikerjakan: [nomor phase]
Error yang terjadi: [paste error]
File yang relevan: [nama file]
```
