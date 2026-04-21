<div>
    {{-- Metric cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs text-gray-500 mb-1">Hot Leads</p>
            <p class="text-3xl font-bold text-red-600">{{ $metrics['hot_leads_count'] }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs text-gray-500 mb-1">Pending Handoffs</p>
            <p class="text-3xl font-bold text-orange-500">{{ $metrics['pending_handoffs_count'] }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs text-gray-500 mb-1">Agent Slots</p>
            <p class="text-3xl font-bold text-indigo-600">{{ $metrics['agent_slots_used'] }}/{{ $metrics['agent_slots_max'] }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs text-gray-500 mb-1">Days Remaining</p>
            <p class="text-3xl font-bold {{ $metrics['subscription_days_remaining'] <= 7 ? 'text-red-500' : 'text-green-600' }}">
                {{ $metrics['subscription_days_remaining'] }}
            </p>
        </div>
    </div>

    {{-- Unpaid billing alert --}}
    @if($metrics['unpaid_billing_count'] > 0)
        <div class="mb-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-800">
            You have <strong>{{ $metrics['unpaid_billing_count'] }}</strong> unpaid billing invoice(s).
            <a href="{{ route('billing.index') }}" class="underline ml-1">View Billing</a>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Hot Leads table --}}
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold">Hot Leads</h3>
                <a href="{{ route('leads.index') }}" class="text-xs text-indigo-600 hover:underline">View all</a>
            </div>
            <div class="divide-y divide-gray-50">
                @forelse($hot_leads as $lead)
                    <a href="{{ route('leads.show', $lead) }}" class="flex items-center justify-between px-4 py-3 hover:bg-gray-50 text-sm">
                        <div>
                            <p class="font-medium">{{ $lead->name ?? $lead->phone_e164 }}</p>
                            <p class="text-xs text-gray-400">{{ $lead->status->value }}</p>
                        </div>
                        <span class="text-xs text-gray-400">{{ $lead->last_message_at?->diffForHumans() }}</span>
                    </a>
                @empty
                    <p class="px-4 py-6 text-sm text-gray-400 text-center">No hot leads</p>
                @endforelse
            </div>
        </div>

        {{-- Pending Handoffs table --}}
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-4 py-3 border-b border-gray-100">
                <h3 class="text-sm font-semibold">Pending Handoffs</h3>
            </div>
            <div class="divide-y divide-gray-50">
                @forelse($pending_handoffs as $handoff)
                    <a href="{{ route('leads.show', $handoff->lead) }}" class="flex items-center justify-between px-4 py-3 hover:bg-gray-50 text-sm">
                        <div>
                            <p class="font-medium">{{ $handoff->lead->name ?? $handoff->lead->phone_e164 }}</p>
                            <p class="text-xs text-gray-400">{{ $handoff->reason }}</p>
                        </div>
                        <span class="text-xs text-gray-400">{{ $handoff->created_at->diffForHumans() }}</span>
                    </a>
                @empty
                    <p class="px-4 py-6 text-sm text-gray-400 text-center">No pending handoffs</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
