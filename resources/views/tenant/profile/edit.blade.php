@extends('layouts.app')

@section('title', 'Profile Tenant')

@section('content')
    @php
        $tenantId = app(\App\Modules\Tenancy\Services\TenantContext::class)->getTenantId();
        $isVendorAdmin = auth()->user()?->tenantRole($tenantId) === \App\Modules\Auth\Enums\TenantUserRole::VendorAdmin;
    @endphp

    <div class="max-w-3xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Profile Tenant</h1>
            <p class="mt-2 text-sm text-gray-500">
                Tentukan layanan utama tenant yang akan dipakai AI sebagai konteks default. User tidak akan lagi ditanya layanan di tahap awal.
            </p>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <dl class="mb-6 space-y-3 text-sm">
                <div class="flex gap-3">
                    <dt class="w-40 text-gray-400">Nama Tenant</dt>
                    <dd class="font-medium text-gray-900">{{ $tenant->name }}</dd>
                </div>
                <div class="flex gap-3">
                    <dt class="w-40 text-gray-400">Slug</dt>
                    <dd class="font-mono text-gray-700">{{ $tenant->slug }}</dd>
                </div>
                <div class="flex gap-3">
                    <dt class="w-40 text-gray-400">Layanan Utama</dt>
                    <dd class="text-gray-900">{{ $tenant->primaryServiceName() ?? 'Belum diatur' }}</dd>
                </div>
            </dl>

            @if(! $isVendorAdmin)
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Hanya vendor admin yang bisa mengubah profile tenant.
                </div>
            @else
                <form method="POST" action="{{ route('tenant-profile.update') }}" class="space-y-5">
                    @csrf
                    <div>
                        <label for="primary_service_catalog_id" class="mb-2 block text-sm font-medium text-gray-700">
                            Layanan Utama
                        </label>
                        <select
                            id="primary_service_catalog_id"
                            name="primary_service_catalog_id"
                            class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                        >
                            <option value="">Pilih layanan utama</option>
                            @foreach($services as $service)
                                <option value="{{ $service->id }}" @selected(old('primary_service_catalog_id', $tenant->primary_service_catalog_id) == $service->id)>
                                    {{ $service->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('primary_service_catalog_id')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        <p class="mt-2 text-xs text-gray-500">
                            Daftar ini diatur dari halaman super admin. Tenant tidak bisa mengetik layanan manual.
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-700">
                            Simpan Profile
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>
@endsection
