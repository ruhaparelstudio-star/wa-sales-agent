@extends('layouts.superadmin')

@section('title', 'Tenant: ' . $tenant->name)

@section('content')
<div class="max-w-4xl mx-auto">
    @if($invitation)
        <div class="mb-6 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
            <div class="font-medium">Link Aktivasi Tenant</div>
            <div class="mt-1">Gunakan link ini jika email invitation belum diterima tenant.</div>
            <div class="mt-3 rounded-lg border border-blue-200 bg-white px-3 py-2 font-mono text-xs break-all">
                {{ url('/auth/activate?token=' . $invitation->token) }}
            </div>
            <div class="mt-2 text-xs text-blue-700">
                Berlaku sampai {{ $invitation->expires_at->format('d M Y H:i') }}.
            </div>
        </div>
    @endif

    <div class="mb-6">
        <a href="{{ route('superadmin.tenants.index') }}" class="text-sm text-gray-400 hover:text-gray-600">← All Tenants</a>
        <h1 class="text-2xl font-bold mt-2">{{ $tenant->name }}</h1>
        <p class="text-sm text-gray-400">slug: {{ $tenant->slug }} · {{ $tenant->is_active ? 'Active' : 'Suspended' }}</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        {{-- Tenant info --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="text-sm font-semibold mb-3">Tenant Info</h2>
            <dl class="space-y-2 text-sm">
                <div class="flex gap-3">
                    <dt class="text-gray-400 w-32">Name</dt>
                    <dd>{{ $tenant->name }}</dd>
                </div>
                <div class="flex gap-3">
                    <dt class="text-gray-400 w-32">Slug</dt>
                    <dd class="font-mono">{{ $tenant->slug }}</dd>
                </div>
                <div class="flex gap-3">
                    <dt class="text-gray-400 w-32">Status</dt>
                    <dd>
                        <span class="px-2 py-0.5 rounded-full text-xs {{ $tenant->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                            {{ $tenant->is_active ? 'Active' : 'Suspended' }}
                        </span>
                    </dd>
                </div>
                <div class="flex gap-3">
                    <dt class="text-gray-400 w-32">Created</dt>
                    <dd>{{ $tenant->created_at->format('d M Y') }}</dd>
                </div>
            </dl>
        </div>

        {{-- Subscription info --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="text-sm font-semibold mb-3">Subscription</h2>
            @if($subscription)
                <dl class="space-y-2 text-sm">
                    <div class="flex gap-3">
                        <dt class="text-gray-400 w-32">Plan</dt>
                        <dd>{{ $subscription->plan->name }}</dd>
                    </div>
                    <div class="flex gap-3">
                        <dt class="text-gray-400 w-32">Status</dt>
                        <dd>
                            <span class="px-2 py-0.5 rounded-full text-xs bg-indigo-100 text-indigo-700">
                                {{ $subscription->status->value }}
                            </span>
                        </dd>
                    </div>
                    <div class="flex gap-3">
                        <dt class="text-gray-400 w-32">Expires</dt>
                        <dd>{{ $subscription->ends_at->format('d M Y') }}</dd>
                    </div>
                    <div class="flex gap-3">
                        <dt class="text-gray-400 w-32">Days Left</dt>
                        <dd class="{{ $subscription->daysUntilExpiry() <= 7 ? 'text-red-600 font-bold' : '' }}">
                            {{ $subscription->daysUntilExpiry() }} days
                        </dd>
                    </div>
                </dl>
            @else
                <p class="text-sm text-gray-400">No active subscription.</p>
            @endif

            {{-- Assign Plan form --}}
            <form action="{{ route('superadmin.subscriptions.assign') }}" method="POST" class="mt-4 pt-4 border-t border-gray-100">
                @csrf
                <input type="hidden" name="tenant_id" value="{{ $tenant->id }}">
                <div class="flex gap-2">
                    <select name="plan_id" class="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm">
                        <option value="">Select Plan</option>
                        @foreach($plans as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }} - Rp {{ number_format($plan->price, 0, ',', '.') }}/bln</option>
                        @endforeach
                    </select>
                    <input name="trial_days" type="number" placeholder="Trial days" min="0"
                           class="w-28 border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg">Assign</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
