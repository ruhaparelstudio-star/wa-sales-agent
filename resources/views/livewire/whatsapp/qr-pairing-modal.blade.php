<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
     x-data="{
         qrData: null,
         eventSource: null,

         initSse(sseUrl) {
             if (!sseUrl) return;
             if (this.eventSource) this.eventSource.close();

             this.eventSource = new EventSource(sseUrl);

             this.eventSource.onmessage = (e) => {
                 const payload = JSON.parse(e.data);

                 if (payload.type === 'qr') {
                     this.qrData = payload.qr?.startsWith('data:image')
                         ? payload.qr
                         : 'data:image/png;base64,' + payload.qr;
                     $wire.$set('status', 'waiting');
                     return;
                 }

                 if (payload.type === 'agent_connected') {
                     this.eventSource.close();
                     $wire.onAgentConnected();
                     $dispatch('agent-connected');
                     return;
                 }

                 if (payload.type === 'session_cancelled') {
                     this.eventSource.close();
                     this.qrData = null;
                 }
             };
         },

         closeAndCancel() {
             if (this.eventSource) this.eventSource.close();
             $wire.closeModal();
         }
     }"
     x-init="
         if ($wire.sseUrl) initSse($wire.sseUrl);
         $watch('$wire.sseUrl', (url) => { if (url) initSse(url); });
         $wire.on('agent-connected', () => $dispatch('agent-connected'));
     ">

    <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold">Connect WhatsApp</h2>
            <button @click="closeAndCancel()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- Status: idle / loading --}}
        @if($status === 'idle')
            <div class="flex flex-col items-center py-8 gap-4">
                <div class="w-12 h-12 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin"></div>
                <p class="text-sm text-gray-500">Initialising...</p>
            </div>
        @endif

        @if($status === 'waiting')
            <div class="flex flex-col items-center py-8 gap-4">
                <template x-if="!qrData">
                    <div class="w-12 h-12 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin"></div>
                </template>
                <template x-if="qrData">
                    <img :src="qrData" class="w-52 h-52 rounded-lg border border-gray-200" alt="QR Code">
                </template>
                <p class="text-sm text-gray-600 text-center">Scan this QR code with<br>your WhatsApp app</p>
            </div>
        @endif

        @if($status === 'scanning')
            <div class="flex flex-col items-center py-8 gap-4">
                <template x-if="qrData">
                    <img :src="qrData" class="w-52 h-52 rounded-lg border border-gray-200" alt="QR Code">
                </template>
                <p class="text-sm text-indigo-600 font-medium">QR scanned! Completing connection...</p>
            </div>
        @endif

        @if($status === 'connected')
            <div class="flex flex-col items-center py-8 gap-4">
                <div class="w-14 h-14 bg-green-100 rounded-full flex items-center justify-center">
                    <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-green-700">Agent connected successfully!</p>
                <button @click="closeAndCancel()"
                        class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg">
                    Done
                </button>
            </div>
        @endif

        @if($status === 'cancelled')
            <div class="flex flex-col items-center py-6 gap-3">
                <p class="text-sm text-gray-500">Pairing cancelled.</p>
                <button wire:click="initiatePairing" class="text-sm text-indigo-600 hover:underline">Try again</button>
            </div>
        @endif

        @if($status === 'error')
            <div class="flex flex-col items-center py-6 gap-3">
                <p class="text-sm text-center text-red-600">{{ $errorMessage ?? 'Unable to start pairing.' }}</p>
                <button wire:click="initiatePairing" class="text-sm text-indigo-600 hover:underline">Try again</button>
            </div>
        @endif
    </div>
</div>
