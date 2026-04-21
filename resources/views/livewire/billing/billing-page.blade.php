<div>
    @php
        $tenantContext = app(\App\Modules\Tenancy\Services\TenantContext::class);
        $tenantId = $tenantContext->isSet() ? $tenantContext->getTenantId() : null;
        $canManageBilling = $tenantId !== null
            && auth()->user()?->hasTenantPermission('manage-billing', $tenantId);
    @endphp
    <h1 class="text-xl font-bold mb-6">Billing</h1>

    {{-- Plan info cards --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs text-gray-500 mb-1">Active Plan</p>
            <p class="text-lg font-bold text-gray-800">{{ $plan?->name ?? '—' }}</p>
            <p class="text-xs text-gray-400">{{ $subscription ? 'Expires ' . $subscription->ends_at->format('d M Y') : 'No active subscription' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs text-gray-500 mb-1">Agent Slots</p>
            <p class="text-lg font-bold text-gray-800">{{ $slotsUsed }} / {{ $plan?->max_agents ?? '—' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs text-gray-500 mb-1">Plan Price</p>
            <p class="text-lg font-bold text-gray-800">
                Rp {{ $plan ? number_format($plan->price, 0, ',', '.') : '—' }}/mo
            </p>
        </div>
    </div>

    {{-- Invoices table --}}
    <div class="bg-white rounded-xl border border-gray-200">
        <div class="px-5 py-4 border-b border-gray-100">
            <h2 class="text-sm font-semibold">Billing History</h2>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-5 py-3 text-left">Invoice #</th>
                        <th class="px-5 py-3 text-left">Period</th>
                        <th class="px-5 py-3 text-right">Amount</th>
                        <th class="px-5 py-3 text-left">Status</th>
                        <th class="px-5 py-3 text-left">Due</th>
                        <th class="px-5 py-3 text-left">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($invoices as $invoice)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 font-mono text-xs">{{ $invoice->invoice_number }}</td>
                            <td class="px-5 py-3 text-gray-500">{{ $invoice->period_start->format('d M') }} – {{ $invoice->period_end->format('d M Y') }}</td>
                            <td class="px-5 py-3 text-right font-medium">Rp {{ number_format($invoice->amount, 0, ',', '.') }}</td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium
                                    {{ $invoice->status->value === 'paid' ? 'bg-green-100 text-green-700' : ($invoice->status->value === 'unpaid' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700') }}">
                                    {{ $invoice->status->value }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-gray-500">{{ $invoice->due_date->format('d M Y') }}</td>
                            <td class="px-5 py-3">
                                @if($canManageBilling && $invoice->status->value === 'unpaid' && !$invoice->proof_path)
                                    @if($uploadingInvoiceId === $invoice->id)
                                        <div class="flex items-center gap-2">
                                            <input type="file" wire:model="proofFile" class="text-xs">
                                            <button wire:click="uploadProof" wire:loading.attr="disabled" wire:target="uploadProof,proofFile" class="text-xs px-2 py-1 bg-indigo-600 text-white rounded">Upload</button>
                                            <button wire:click="$set('uploadingInvoiceId', null)" wire:loading.attr="disabled" class="text-xs text-gray-400">Cancel</button>
                                        </div>
                                    @else
                                        <button wire:click="$set('uploadingInvoiceId', {{ $invoice->id }})"
                                                wire:loading.attr="disabled"
                                                class="text-xs text-indigo-600 hover:underline">
                                            Upload Proof
                                        </button>
                                    @endif
                                @elseif($invoice->proof_path && $invoice->status->value === 'unpaid')
                                    <span class="text-xs text-yellow-600">Awaiting approval</span>
                                @else
                                    <span class="text-xs text-gray-300">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-400">No billing history</td>
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
