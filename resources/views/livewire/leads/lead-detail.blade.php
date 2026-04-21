<div>
    @php
        $tenantContext = app(\App\Modules\Tenancy\Services\TenantContext::class);
        $tenantId = $tenantContext->isSet() ? $tenantContext->getTenantId() : null;
        $canTakeover = $tenantId !== null
            && auth()->user()?->hasTenantPermission('takeover-chat', $tenantId);
        $canManageInvoices = $tenantId !== null
            && auth()->user()?->hasTenantPermission('manage-invoices', $tenantId);
    @endphp
    {{-- Header --}}
    <div class="mb-6">
        <div class="flex items-start justify-between">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <a href="{{ route('leads.index') }}" class="text-sm text-gray-400 hover:text-gray-600">← Leads</a>
                </div>
                <h1 class="text-xl font-bold">{{ $lead->name ?? $lead->phone_e164 }}</h1>
                <p class="text-sm text-gray-400 mt-0.5">{{ $lead->phone_e164 }} · {{ $lead->status->value }} · {{ $lead->whatsappAgent?->phone_number ?? 'No agent' }}</p>
            </div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="flex gap-1 mb-4 border-b border-gray-200">
        @foreach(array_filter([
            'profile' => 'Profil',
            'conversation' => 'Percakapan',
            'handoffs' => 'Handoffs',
            'invoices' => $canManageInvoices ? 'Invoices' : null,
        ]) as $tab => $label)
            <button wire:click="$set('activeTab', '{{ $tab }}')"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px
                        {{ $activeTab === $tab ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Tab: Profile --}}
    @if($activeTab === 'profile')
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-semibold mb-3">Lead Profile</h3>
                @if($lead->profile)
                    <dl class="space-y-2 text-sm">
                        @foreach((array)$lead->profile->toArray() as $key => $value)
                            @if(!in_array($key, ['id','lead_id','created_at','updated_at']) && $value !== null)
                                <div class="flex gap-3">
                                    <dt class="text-gray-400 w-32 flex-shrink-0">{{ ucwords(str_replace('_', ' ', $key)) }}</dt>
                                    <dd class="text-gray-800">{{ is_array($value) ? implode(', ', $value) : $value }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                @else
                    <p class="text-sm text-gray-400">No profile data yet.</p>
                @endif
            </div>
        </div>
    @endif

    {{-- Tab: Conversation --}}
    @if($activeTab === 'conversation')
        @if($active_conversation)
            <div class="bg-white rounded-xl border border-gray-200" wire:poll.3s.visible>
                <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100">
                    <span class="text-sm font-medium">Conversation #{{ $active_conversation->id }}</span>
                    <div class="flex gap-2">
                        @if($canTakeover && !$active_conversation->is_human_takeover)
                            <button wire:click="takeover({{ $active_conversation->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="takeover({{ $active_conversation->id }})"
                                    class="text-xs px-3 py-1 bg-orange-500 text-white rounded-lg hover:bg-orange-600">
                                Takeover
                            </button>
                        @elseif($active_conversation->is_human_takeover)
                            <span class="text-xs px-3 py-1 bg-gray-100 text-gray-600 rounded-lg">Human Takeover Active</span>
                        @endif
                        @if($active_conversation->isHandoff())
                            <span class="text-xs px-3 py-1 bg-orange-100 text-orange-700 rounded-lg">Waiting Admin</span>
                        @endif
                    </div>
                </div>

                {{-- Messages --}}
                <div class="p-4 space-y-3 max-h-[60vh] overflow-y-auto">
                    @foreach($messages as $message)
                        <div class="flex {{ $message->direction->value === 'inbound' ? 'justify-start' : 'justify-end' }}">
                            <div class="max-w-xs lg:max-w-md px-3 py-2 rounded-xl text-sm
                                {{ $message->direction->value === 'inbound'
                                    ? 'bg-gray-100 text-gray-800'
                                    : ($message->is_from_ai ? 'bg-indigo-600 text-white' : 'bg-gray-600 text-white') }}">
                                @if($message->isMedia())
                                    @if(str_starts_with($message->media_mime ?? '', 'image/'))
                                        <img src="{{ $message->media_url }}" class="rounded-lg max-w-full" alt="Image">
                                    @else
                                        <div class="flex items-center gap-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                            </svg>
                                            <span>{{ $message->media_filename ?? 'Document' }}</span>
                                        </div>
                                    @endif
                                @else
                                    {{ $message->content }}
                                @endif
                                <p class="text-xs mt-1 opacity-60">{{ $message->created_at->format('H:i') }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if($messages->hasPages())
                    <div class="px-4 py-2 border-t border-gray-100">
                        {{ $messages->links() }}
                    </div>
                @endif

                {{-- Reply box --}}
                @if($canTakeover)
                    <div class="px-4 py-3 border-t border-gray-200 bg-gray-50 rounded-b-xl">
                        @if(!$active_conversation->is_human_takeover)
                            <p class="text-xs text-amber-600 mb-2">
                                Klik <strong>Takeover</strong> terlebih dahulu agar AI berhenti membalas sebelum kamu menulis.
                            </p>
                        @endif
                        <div class="flex gap-2 items-end">
                            <textarea
                                wire:model="replyMessage"
                                wire:keydown.ctrl.enter="sendReply"
                                rows="2"
                                placeholder="Ketik balasan... (Ctrl+Enter untuk kirim)"
                                class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            ></textarea>
                            <button
                                wire:click="sendReply"
                                wire:loading.attr="disabled"
                                wire:target="sendReply"
                                class="flex-shrink-0 px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 disabled:opacity-50 flex items-center gap-1.5">
                                <span wire:loading.remove wire:target="sendReply">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                                    </svg>
                                </span>
                                <span wire:loading wire:target="sendReply">
                                    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                                    </svg>
                                </span>
                                Kirim
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        @else
            <p class="text-sm text-gray-400 py-8 text-center">No active conversation.</p>
        @endif
    @endif

    {{-- Tab: Handoffs --}}
    @if($activeTab === 'handoffs')
        <div class="bg-white rounded-xl border border-gray-200" wire:poll.5s.visible>
            <div class="divide-y divide-gray-100">
                @forelse($pending_handoffs as $handoff)
                    <div class="flex items-center justify-between px-5 py-4">
                        <div>
                            <p class="text-sm font-medium">{{ $handoff->reason }}</p>
                            <p class="text-xs text-gray-400">{{ $handoff->created_at->diffForHumans() }}</p>
                        </div>
                        <button wire:click="resolveHandoff({{ $handoff->id }})"
                                wire:loading.attr="disabled"
                                wire:target="resolveHandoff({{ $handoff->id }})"
                                class="text-xs px-3 py-1 bg-green-100 text-green-700 rounded-lg hover:bg-green-200">
                            Resolve
                        </button>
                    </div>
                @empty
                    <p class="px-5 py-8 text-sm text-gray-400 text-center">No pending handoffs</p>
                @endforelse
            </div>
        </div>
    @endif

    {{-- Tab: Invoices --}}
    @if($canManageInvoices && $activeTab === 'invoices')
        <div class="space-y-4">
            {{-- Invoice list --}}
            <div class="bg-white rounded-xl border border-gray-200">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                    <span class="text-sm font-semibold">Invoices</span>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse($invoices as $invoice)
                        <div class="flex items-center justify-between px-5 py-3 text-sm">
                            <div>
                                <p class="font-mono text-xs text-gray-500">{{ $invoice->invoice_number }}</p>
                                <p class="font-medium">Rp {{ number_format($invoice->amount, 0, ',', '.') }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-xs px-2 py-0.5 rounded-full
                                    {{ $invoice->status->value === 'paid' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">
                                    {{ $invoice->status->value }}
                                </span>
                                @if($invoice->status->value === 'draft')
                                    <button wire:click="sendInvoice({{ $invoice->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="sendInvoice({{ $invoice->id }})"
                                            class="text-xs text-indigo-600 hover:underline">Send</button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="px-5 py-6 text-sm text-gray-400 text-center">No invoices</p>
                    @endforelse
                </div>
            </div>

            {{-- Create invoice form --}}
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-semibold mb-3">Create Invoice</h3>
                <div class="space-y-3">
                    @foreach($lineItems as $i => $item)
                        <div class="flex gap-2">
                            <input wire:model="lineItems.{{ $i }}.description" placeholder="Description"
                                   class="flex-1 border border-gray-200 rounded px-3 py-1.5 text-sm">
                            <input wire:model="lineItems.{{ $i }}.amount" type="number" placeholder="Amount"
                                   class="w-32 border border-gray-200 rounded px-3 py-1.5 text-sm">
                        </div>
                    @endforeach
                    <button wire:click="$push('lineItems', {description: '', amount: 0})"
                            class="text-xs text-indigo-600 hover:underline">+ Add Line Item</button>
                </div>
                <div class="mt-3 flex items-center gap-3">
                    <input wire:model="dueDate" type="date" class="border border-gray-200 rounded px-3 py-1.5 text-sm">
                    <button wire:click="createInvoice"
                            wire:loading.attr="disabled"
                            wire:target="createInvoice"
                            class="px-4 py-1.5 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">
                        Create
                    </button>
                </div>
            </div>

            {{-- Upload invoice --}}
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-semibold mb-3">Upload Invoice PDF</h3>
                <div class="flex items-center gap-3">
                    <input wire:model="invoiceFile" type="file" accept=".pdf" class="text-sm">
                    <button wire:click="uploadInvoice"
                            wire:loading.attr="disabled"
                            wire:target="uploadInvoice,invoiceFile"
                            class="px-4 py-1.5 bg-gray-700 text-white text-sm rounded-lg hover:bg-gray-800">
                        Upload
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
