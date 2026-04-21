@extends('layouts.superadmin')

@section('title', 'Buat Tenant')

@section('content')
<div class="max-w-3xl">
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <form method="POST" action="{{ route('superadmin.tenants.store') }}" class="space-y-5">
            @csrf

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Nama Vendor</label>
                <input type="text" name="name" value="{{ old('name') }}" placeholder="Contoh: Foto Studio Andara" required
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Slug</label>
                <input type="text" name="slug" value="{{ old('slug') }}" placeholder="foto-studio-andara" required
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <p class="mt-1 text-xs text-gray-500">Huruf kecil, angka, dan tanda hubung saja.</p>
                @error('slug')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nama Admin</label>
                    <input type="text" name="admin_name" value="{{ old('admin_name') }}" required
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    @error('admin_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email Admin</label>
                    <input type="email" name="admin_email" value="{{ old('admin_email') }}" required
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    @error('admin_email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Plan</label>
                    <select name="plan_id" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        <option value="">Pilih plan</option>
                        @foreach($plans as $plan)
                            <option value="{{ $plan->id }}" @selected((string) old('plan_id') === (string) $plan->id)>
                                {{ $plan->name }} - Rp {{ number_format($plan->price, 0, ',', '.') }}/bln
                            </option>
                        @endforeach
                    </select>
                    @error('plan_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Trial Days</label>
                    <input type="number" name="trial_days" value="{{ old('trial_days', 14) }}" min="0"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <p class="mt-1 text-xs text-gray-500">0 berarti tenant langsung masuk alur billing.</p>
                    @error('trial_days')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                    Buat Tenant
                </button>
                <a href="{{ route('superadmin.tenants.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
