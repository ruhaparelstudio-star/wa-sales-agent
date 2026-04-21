<div>
    @php
        $tenantContext = app(\App\Modules\Tenancy\Services\TenantContext::class);
        $tenantId = $tenantContext->isSet() ? $tenantContext->getTenantId() : null;
        $canManageInvoices = $tenantId !== null
            && auth()->user()?->hasTenantPermission('manage-invoices', $tenantId);
    @endphp
    <h1 class="text-xl font-bold mb-4">Invoices</h1>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3 mb-4">
        <input wire:model.live.debounce.300ms="search"
               type="text" placeholder="Search lead name..."
               class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-48 focus:outline-none focus:ring-2 focus:ring-indigo-300">

        <select wire:model.live="statusFilter" class="border border-gray-200 rounded-lg px-3 py-2 text-sm">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->value }}</option>
            @endforeach
        </select>

        <input wire:model.live="dateFrom" type="date" class="border border-gray-200 rounded-lg px-3 py-2 text-sm">
        <input wire:model.live="dateTo" type="date" class="border border-gray-200 rounded-lg px-3 py-2 text-sm">
    </div>

    <div class="bg-white rounded-xl border border-gray-200">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-5 py-3 text-left">Invoice #</th>
                        <th class="px-5 py-3 text-left">Lead</th>
                        <th class="px-5 py-3 text-right">Amount</th>
                        <th class="px-5 py-3 text-left">Status</th>
                        <th class="px-5 py-3 text-left">Due Date</th>
                        <th class="px-5 py-3 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($invoices as $invoice)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 font-mono text-xs">{{ $invoice->invoice_number }}</td>
                            <td class="px-5 py-3">
                                <a href="{{ route('leads.show', $invoice->lead) }}"
                                   class="text-indigo-600 hover:underline">
                                    {{ $invoice->lead->name ?? $invoice->lead->phone_e164 }}
                                </a>
                            </td>
                            <td class="px-5 py-3 text-right font-medium">
                                Rp {{ number_format($invoice->amount, 0, ',', '.') }}
                            </td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium
                                    {{ match($invoice->status->value) {
                                        'paid' => 'bg-green-100 text-green-700',
                                        'sent' => 'bg-blue-100 text-blue-700',
                                        'draft' => 'bg-gray-100 text-gray-600',
                                        default => 'bg-yellow-100 text-yellow-700'
                                    } }}">
                                    {{ $invoice->status->value }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-gray-500 text-xs">
                                {{ $invoice->due_date?->format('d M Y') ?? '—' }}
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('leads.show', $invoice->lead) }}"
                                       class="text-xs text-gray-500 hover:text-indigo-600">Detail</a>
                                    @if($canManageInvoices && $invoice->status->value === 'draft')
                                        <button wire:click="send({{ $invoice->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="send({{ $invoice->id }})"
                                                class="text-xs text-indigo-600 hover:underline">Send</button>
                                    @endif
                                    @if($canManageInvoices && $invoice->status->value !== 'paid')
                                        <button wire:click="markPaid({{ $invoice->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="markPaid({{ $invoice->id }})"
                                                wire:confirm="Mark this invoice as paid?"
                                                class="text-xs text-green-600 hover:underline">Mark Paid</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-400">No invoices found</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3 border-t border-gray-100">
            {{ $invoices->links() }}
        </div>
    </div>
</div>
