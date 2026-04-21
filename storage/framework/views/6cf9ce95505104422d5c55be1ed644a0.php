<div class="relative" x-data="{
    prevCount: <?php echo e($unreadCount); ?>,
    audioCtx: null,
    initAudio() {
        if (!this.audioCtx) {
            try { this.audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e) {}
        }
        if (this.audioCtx && this.audioCtx.state === 'suspended') {
            this.audioCtx.resume();
        }
    },
    playNotifSound() {
        if (!this.audioCtx || this.audioCtx.state !== 'running') return;
        try {
            const ctx = this.audioCtx;
            const t = ctx.currentTime;
            [523, 659].forEach((freq, i) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0, t + i * 0.12);
                gain.gain.linearRampToValueAtTime(0.25, t + i * 0.12 + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, t + i * 0.12 + 0.18);
                osc.start(t + i * 0.12);
                osc.stop(t + i * 0.12 + 0.2);
            });
        } catch(e) {}
    }
}" x-init="
    document.addEventListener('click', () => initAudio(), { once: true });
    $watch('$wire.unreadCount', (val) => {
        if (val > prevCount) playNotifSound();
        prevCount = val;
    });
">
    <button wire:click="toggle" class="relative p-2 text-gray-500 hover:text-gray-700 focus:outline-none">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($unreadCount > 0): ?>
            <span class="absolute top-1 right-1 w-4 h-4 flex items-center justify-center bg-red-500 text-white text-xs rounded-full">
                <?php echo e($unreadCount > 9 ? '9+' : $unreadCount); ?>

            </span>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </button>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($open): ?>
    <div class="absolute right-0 mt-2 w-96 bg-white border border-gray-200 rounded-xl shadow-lg z-50"
         x-data x-init="$el.addEventListener('click', e => e.stopPropagation())"
         @click.away.window="$wire.toggle()">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
            <span class="text-sm font-semibold text-gray-800">Notifikasi</span>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($unreadCount > 0): ?>
                <button wire:click="markAllAsRead" class="text-xs text-indigo-600 hover:underline">Tandai semua dibaca</button>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>

        <div class="max-h-96 overflow-y-auto divide-y divide-gray-50">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $notifications; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $notif): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <?php
                    $type    = $notif->data['type'] ?? '';
                    $message = $notif->data['message'] ?? null;
                    $url     = $notif->data['url'] ?? $notif->data['conversation_url'] ?? $notif->data['lead_url'] ?? $notif->data['reconnect_url'] ?? $notif->data['upgrade_url'] ?? null;

                    [$dot, $label] = match($type) {
                        'handoff_created'    => ['bg-orange-400', 'Handoff'],
                        'hot_lead_alert'     => ['bg-red-400',    'Hot Lead'],
                        'agent_disconnected' => ['bg-yellow-400', 'Agent Putus'],
                        'agent_slot_limit'   => ['bg-purple-400', 'Slot Penuh'],
                        'billing_alert'      => ['bg-blue-400',   'Billing'],
                        'payment_approved'   => ['bg-green-400',  'Pembayaran'],
                        'payment_received'   => ['bg-teal-400',   'Bukti Bayar'],
                        'renewal_invoice'    => ['bg-indigo-400', 'Invoice'],
                        default              => ['bg-gray-400',   'Info'],
                    };

                    if (! $message) {
                        $message = match($type) {
                            'handoff_created'    => '🤝 Lead ' . ($notif->data['lead_name'] ?? '?') . ' butuh penanganan — ' . ($notif->data['reason_label'] ?? $notif->data['reason'] ?? ''),
                            'hot_lead_alert'     => '🔥 Lead ' . ($notif->data['lead_name'] ?? '?') . ' siap ditangani',
                            'agent_disconnected' => '⚠️ Nomor WA ' . ($notif->data['phone_number'] ?? '?') . ' terputus',
                            'agent_slot_limit'   => '📵 Batas slot agent tercapai (' . ($notif->data['current_connected'] ?? '?') . '/' . ($notif->data['max_agents'] ?? '?') . ')',
                            default              => 'Notifikasi baru',
                        };
                    }
                ?>

                <div class="px-4 py-3 <?php echo e($notif->read_at ? 'bg-white' : 'bg-indigo-50'); ?> text-sm group">
                    <div class="flex items-start gap-3">
                        <span class="mt-1 flex-shrink-0 w-2 h-2 rounded-full <?php echo e($dot); ?>"></span>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-0.5">
                                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide"><?php echo e($label); ?></span>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$notif->read_at): ?>
                                    <span class="w-1.5 h-1.5 rounded-full bg-indigo-500 flex-shrink-0"></span>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($url): ?>
                                <a href="<?php echo e($url); ?>" wire:click="markAsRead('<?php echo e($notif->id); ?>')"
                                   class="block text-gray-800 hover:text-indigo-700 leading-snug">
                                    <?php echo e($message); ?>

                                </a>
                            <?php else: ?>
                                <p class="text-gray-800 leading-snug"><?php echo e($message); ?></p>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <div class="flex items-center justify-between mt-1.5">
                                <span class="text-xs text-gray-400"><?php echo e($notif->created_at->diffForHumans()); ?></span>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$notif->read_at): ?>
                                    <button wire:click="markAsRead('<?php echo e($notif->id); ?>')"
                                            class="text-xs text-indigo-500 hover:underline opacity-0 group-hover:opacity-100 transition-opacity">
                                        Tandai dibaca
                                    </button>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <div class="px-4 py-8 text-center">
                    <svg class="w-8 h-8 mx-auto text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                    </svg>
                    <p class="text-sm text-gray-400">Tidak ada notifikasi</p>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /var/www/resources/views/livewire/dashboard/notification-bell.blade.php ENDPATH**/ ?>