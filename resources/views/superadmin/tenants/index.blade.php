@extends('layouts.superadmin')

@section('title', 'Daftar Tenant')

@section('content')
<div class="space-y-6">
    @if (session('activation_url'))
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
            <div class="font-medium">Link aktivasi tenant</div>
            <div class="mt-1">Jika email belum masuk, buka link ini langsung untuk aktivasi akun tenant:</div>
            <div class="mt-3 rounded-lg border border-blue-200 bg-white px-3 py-2 font-mono text-xs break-all">
                {{ session('activation_url') }}
            </div>
        </div>
    @endif

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Daftar Tenant</h1>
            <p class="text-sm text-gray-500 mt-1">Kelola onboarding tenant dan lihat status tenant yang sudah aktif.</p>
        </div>
        <a href="{{ route('superadmin.tenants.create') }}"
           class="inline-flex items-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
            + Buat Tenant
        </a>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-5 py-3">#</th>
                        <th class="px-5 py-3">Nama</th>
                        <th class="px-5 py-3">Slug</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Dibuat</th>
                        <th class="px-5 py-3">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($tenants as $tenant)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-4 text-gray-500">{{ $tenant->id }}</td>
                            <td class="px-5 py-4">
                                <div class="font-medium text-gray-900">{{ $tenant->name }}</div>
                                <div class="text-xs text-gray-500">{{ $tenant->tenantUsers->count() }} membership</div>
                            </td>
                            <td class="px-5 py-4 font-mono text-xs text-gray-600">{{ $tenant->slug }}</td>
                            <td class="px-5 py-4">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $tenant->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $tenant->is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-gray-500">{{ $tenant->created_at->format('d M Y') }}</td>
                            <td class="px-5 py-4">
                                <a href="{{ route('superadmin.tenants.show', $tenant->id) }}"
                                   class="text-sm font-medium text-indigo-600 hover:text-indigo-700">
                                    Detail
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-400">Belum ada tenant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-gray-100 px-5 py-3">
            {{ $tenants->links() }}
        </div>
    </div>
</div>
@endsection
