<div>
    @php
        $tenantContext = app(\App\Modules\Tenancy\Services\TenantContext::class);
        $tenantId = $tenantContext->isSet() ? $tenantContext->getTenantId() : null;
        $canManageLeads = $tenantId !== null
            && auth()->user()?->hasTenantPermission('manage-leads', $tenantId);
    @endphp
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-bold">Leads</h1>
        <span class="text-sm text-gray-400">{{ $leads->total() }} total</span>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3 mb-4">
        <input wire:model.live.debounce.300ms="search"
               type="text"
               placeholder="Search name or number..."
               class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-56 focus:outline-none focus:ring-2 focus:ring-indigo-300">

        <select wire:model.live="statusFilter" class="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->value }}</option>
            @endforeach
        </select>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-5 py-3 text-left">Lead</th>
                        <th class="px-5 py-3 text-left">Status</th>
                        <th class="px-5 py-3 text-left">Last Message</th>
                        <th class="px-5 py-3 text-left">Agent</th>
                        <th class="px-5 py-3 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($leads as $lead)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3">
                                <p class="font-medium">{{ $lead->name ?? '—' }}</p>
                                <p class="text-xs text-gray-400">{{ $lead->phone_e164 }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium
                                    {{ match($lead->status->value) {
                                        'HOT', 'READY_FOR_HUMAN' => 'bg-red-100 text-red-700',
                                        'QUALIFIED', 'INTERESTED' => 'bg-blue-100 text-blue-700',
                                        'NEW' => 'bg-gray-100 text-gray-600',
                                        default => 'bg-gray-100 text-gray-500'
                                    } }}">
                                    {{ $lead->status->value }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-gray-400 text-xs">
                                {{ $lead->last_message_at?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="px-5 py-3 text-xs text-gray-500">
                                {{ $lead->whatsappAgent?->phone_number ?? '—' }}
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('leads.show', $lead) }}"
                                       class="text-xs text-indigo-600 hover:underline">Detail</a>
                                    @if($canManageLeads)
                                        <button wire:click="toggleAutomation({{ $lead->id }})"
                                                class="text-xs {{ $lead->automation_paused ? 'text-green-600' : 'text-orange-500' }} hover:underline">
                                            {{ $lead->automation_paused ? 'Resume' : 'Pause' }}
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-sm text-gray-400">No leads found</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-5 py-3 border-t border-gray-100">
            {{ $leads->links() }}
        </div>
    </div>
</div>
