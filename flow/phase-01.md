# Phase 01 — Foundation

## Tenancy, Auth, Users, Roles, Onboarding

> Pastikan kamu sudah paste CONTEXT.md sebelum ini.

---

## Yang Dibangun di Phase Ini

1. Laravel project setup dengan modular monolith structure
2. Tenancy core: tenant resolution, isolation
3. Auth: login, roles, permissions (Spatie)
4. Tenant onboarding: super admin buat tenant baru + invitation email
5. Middleware: TenantResolver
6. Foundation policies

---

## Migrations yang Dibuat

### tenants

```sql
id, name, slug (unique), is_active (bool, default true),
settings (json, nullable), created_at, updated_at
INDEX: (slug)
```

### users

```sql
id, name, email (unique), password, email_verified_at,
is_super_admin (bool, default false), remember_token,
created_at, updated_at
```

### tenant_users

```sql
id, tenant_id (FK), user_id (FK), role (enum: vendor_admin|vendor_staff),
created_at, updated_at
UNIQUE: (tenant_id, user_id)
INDEX: (tenant_id, role)
```

### tenant_invitations

```sql
id, tenant_id (FK), user_id (FK), token (unique, string 64),
expires_at (timestamp), accepted_at (timestamp nullable),
created_at, updated_at
INDEX: (token), INDEX: (expires_at), INDEX: (tenant_id)
```

---

## Models yang Dibuat

```
Tenant          → hasMany(TenantUser), hasMany(User through TenantUser)
User            → belongsToMany(Tenant via tenant_users), hasMany(TenantInvitation)
TenantUser      → belongsTo(Tenant), belongsTo(User)
TenantInvitation → belongsTo(Tenant), belongsTo(User)
```

Tenant model: scope `active()`, method `isExpired()` (untuk subscription nanti)
User model: method `isSuperAdmin()`, `belongsToTenant(tenantId)`

---

## Enums

```php
// app/Modules/Auth/Enums/TenantUserRole.php
enum TenantUserRole: string {
    case VendorAdmin = 'vendor_admin';
    case VendorStaff = 'vendor_staff';
}
```

---

## Services yang Dibuat

### TenantResolver (app/Modules/Tenancy/Services/)

Resolve tenant dari:

1. Auth user → ambil tenant dari tenant_users
2. Fallback: session tenant_id

Method: `resolve(Request $request): Tenant`

### TenantContext (app/Modules/Tenancy/Services/)

Singleton yang menyimpan current tenant dalam request lifecycle.
Method: `set(Tenant $tenant)`, `get(): Tenant`, `getTenantId(): int`

### TenantGuardService (app/Modules/Tenancy/Services/)

Method: `assertUserBelongsToTenant(User $user, Tenant $tenant): void`
Throw `AuthorizationException` jika tidak match.

### TenantMembershipService (app/Modules/Auth/Services/)

Method:

- `addUserToTenant(Tenant $tenant, User $user, TenantUserRole $role): TenantUser`
- `getUserRole(Tenant $tenant, User $user): ?TenantUserRole`
- `getAdminsForTenant(Tenant $tenant): Collection`

### AuthService (app/Modules/Auth/Services/)

Method:

- `login(string $email, string $password): User`
- `logout(): void`

---

## Actions yang Dibuat

### CreateTenantAction (app/Modules/Tenancy/Actions/)

Input DTO: `{ name, slug, admin_name, admin_email, plan_id, trial_days }`
Steps:

1. Buat `Tenant` record
2. Buat `User` (vendor_admin) — password random atau dari form
3. Buat `TenantUser` record
4. Buat `TenantInvitation` (token 64 char random, expires 48 jam)
5. Dispatch `SendTenantInvitationJob`

### ActivateInvitationAction (app/Modules/Tenancy/Actions/)

Input: `{ token, password }`
Steps:

1. Cari invitation by token, cek belum expired, belum accepted
2. Set user password
3. Mark `accepted_at`
4. Return user untuk auto-login

---

## Middleware

### TenantResolver Middleware (app/Modules/Tenancy/Http/Middleware/)

- Baca auth user dari session/token
- Resolve tenant via TenantResolver service
- Set ke TenantContext
- Abort 403 jika user tidak punya akses ke tenant

---

## Spatie Permission Setup

Roles yang di-seed:

- `super_admin` (global, tidak tenant-scoped)
- `vendor_admin` (tenant-scoped via tenant_users.role)
- `vendor_staff` (tenant-scoped)

Permissions (minimum untuk auth gates):

- `manage-agents`, `manage-knowledge`, `manage-billing`, `view-leads`
- `manage-leads`, `manage-invoices`, `takeover-chat`

---

## Mail

### TenantInvitationMail (app/Modules/Tenancy/Mail/)

Dikirim ke vendor admin baru.
Isi: nama vendor, nama admin, link aktivasi dengan token.
Link: `{APP_URL}/auth/activate?token={token}`

---

## Routes (routes/web.php dan routes/api.php)

```php
// Auth routes
POST /auth/login
POST /auth/logout
GET  /auth/activate  → show form set password
POST /auth/activate  → ActivateInvitationAction

// Super admin (middleware: auth + super_admin check)
GET  /superadmin/tenants
GET  /superadmin/tenants/create
POST /superadmin/tenants        → CreateTenantAction
```

---

## Tests (Pest)

```
✓ tenant isolation: user A tidak bisa akses data tenant B
✓ TenantMembershipService: add user, get role, get admins
✓ CreateTenantAction: buat tenant + user + invitation
✓ ActivateInvitationAction: token valid, token expired, token sudah dipakai
✓ TenantResolver middleware: reject request tanpa valid tenant membership
✓ Super admin bypass: super admin bisa akses semua tenant
```

---

## Setelah Selesai, Laporkan

1. Semua file yang dibuat beserta path lengkap
2. Cara tenant isolation dimulai dari sini
3. Indexes yang ditambahkan
4. Packages yang perlu di-install (`composer require ...`)
5. TODOs yang belum resolved
