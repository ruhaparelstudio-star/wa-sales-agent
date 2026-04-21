@extends('layouts.superadmin')

@section('title', 'Billing — Super Admin')

@section('content')
<div>
    <h1 class="text-2xl font-bold mb-6">Billing — Unpaid Invoices</h1>

    <div class="bg-white rounded-xl border border-gray-200">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-5 py-3 text-left">Invoice #</th>
                        <th class="px-5 py-3 text-left">Tenant</th>
                        <th class="px-5 py-3 text-right">Amount</th>
                        <th class="px-5 py-3 text-left">Due</th>
                        <th class="px-5 py-3 text-left">Proof</th>
                        <th class="px-5 py-3 text-left">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($invoices as $invoice)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 font-mono text-xs">{{ $invoice->invoice_number }}</td>
                            <td class="px-5 py-3">{{ $invoice->tenant->name }}</td>
                            <td class="px-5 py-3 text-right font-medium">Rp {{ number_format($invoice->amount, 0, ',', '.') }}</td>
                            <td class="px-5 py-3 text-xs text-gray-500">{{ $invoice->due_date->format('d M Y') }}</td>
                            <td class="px-5 py-3">
                                @if($invoice->proof_path)
                                    <a href="{{ Storage::url($invoice->proof_path) }}" target="_blank"
                                       class="text-xs text-indigo-600 hover:underline">View Proof</a>
                                @else
                                    <span class="text-xs text-gray-400">No proof</span>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                @if($invoice->proof_path)
                                    <form action="{{ route('superadmin.billing.approve', $invoice->id) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs px-3 py-1 bg-green-100 text-green-700 rounded hover:bg-green-200">
                                            Approve
                                        </button>
                                    </form>
                                @else
                                    <span class="text-xs text-gray-300">Awaiting proof</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-400">No unpaid invoices</td>
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
@endsection
