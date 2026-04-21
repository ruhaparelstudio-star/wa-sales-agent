@extends('layouts.superadmin')

@section('title', 'LLM Usage — Super Admin')

@section('content')
<div>
    <h1 class="text-2xl font-bold mb-2">LLM Usage — {{ now()->format('F Y') }}</h1>
    <p class="text-sm text-gray-400 mb-6">Token usage per tenant this month.</p>

    <div class="bg-white rounded-xl border border-gray-200">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-5 py-3 text-left">Tenant</th>
                        <th class="px-5 py-3 text-right">Calls</th>
                        <th class="px-5 py-3 text-right">Prompt Tokens</th>
                        <th class="px-5 py-3 text-right">Completion Tokens</th>
                        <th class="px-5 py-3 text-right">Total Tokens</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($rows as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 font-medium">{{ $tenants[$row->tenant_id]?->name ?? 'Unknown' }}</td>
                            <td class="px-5 py-3 text-right">{{ number_format($row->call_count) }}</td>
                            <td class="px-5 py-3 text-right">{{ number_format($row->prompt_tokens) }}</td>
                            <td class="px-5 py-3 text-right">{{ number_format($row->completion_tokens) }}</td>
                            <td class="px-5 py-3 text-right font-medium">{{ number_format($row->total_tokens) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-sm text-gray-400">No usage data this month</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3 border-t border-gray-100">
            {{ $rows->links() }}
        </div>
    </div>
</div>
@endsection
