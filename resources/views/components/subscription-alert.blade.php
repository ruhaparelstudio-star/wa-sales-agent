@php
    $alertService = app(\App\Modules\Dashboard\Services\AlertService::class);
    $tenant = app(\App\Modules\Tenancy\Services\TenantContext::class)->get();
    $level  = $alertService->getSubscriptionAlertLevel($tenant);
    $message = $alertService->getSubscriptionAlertMessage($tenant);
@endphp

@if($level !== null)
    <div class="px-6 py-2 text-sm font-medium
        @if($level === 'critical') bg-red-600 text-white
        @elseif($level === 'danger') bg-orange-500 text-white
        @else bg-yellow-100 text-yellow-800 border-b border-yellow-200
        @endif">
        {{ $message }}
        @if($level !== 'warning')
            <a href="{{ route('billing.index') }}" class="underline ml-2">View Billing</a>
        @endif
    </div>
@endif
