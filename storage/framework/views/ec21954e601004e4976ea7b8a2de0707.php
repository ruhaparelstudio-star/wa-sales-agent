<div>
    <?php
        $tenantContext = app(\App\Modules\Tenancy\Services\TenantContext::class);
        $tenantId = $tenantContext->isSet() ? $tenantContext->getTenantId() : null;
        $canManageAgents = $tenantId !== null
            && auth()->user()?->hasTenantPermission('manage-agents', $tenantId);
    ?>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-bold">WhatsApp Agents</h1>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canManageAgents): ?>
            <button wire:click="openQrModal" wire:loading.attr="disabled" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">
                + Add Agent
            </button>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $agents; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $agent): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="flex items-center justify-between px-5 py-4">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center
                        <?php echo e($agent->isConnected() ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400'); ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium"><?php echo e($agent->phone_number ?? 'Pending...'); ?></p>
                        <p class="text-xs text-gray-400">
                            <span class="inline-flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full <?php echo e($agent->isConnected() ? 'bg-green-500' : 'bg-gray-400'); ?>"></span>
                                <?php echo e($agent->status->value); ?>

                            </span>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($agent->last_connected_at): ?>
                                · Last seen <?php echo e($agent->last_connected_at->diffForHumans()); ?>

                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </p>
                    </div>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($agent->is_default): ?>
                        <span class="ml-2 px-2 py-0.5 bg-indigo-100 text-indigo-700 text-xs rounded-full">Default</span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>

                <div class="flex items-center gap-2">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canManageAgents && !$agent->is_default && $agent->isConnected()): ?>
                        <button wire:click="setDefault('<?php echo e($agent->id); ?>')" wire:loading.attr="disabled" wire:target="setDefault('<?php echo e($agent->id); ?>')" class="text-xs text-gray-500 hover:text-indigo-600 px-2 py-1 border border-gray-200 rounded">
                            Set Default
                        </button>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canManageAgents && $agent->isConnected()): ?>
                        <button wire:click="disconnect('<?php echo e($agent->id); ?>')"
                                wire:loading.attr="disabled"
                                wire:target="disconnect('<?php echo e($agent->id); ?>')"
                                wire:confirm="Disconnect this agent?"
                                class="text-xs text-red-500 hover:text-red-700 px-2 py-1 border border-red-200 rounded">
                            Disconnect
                        </button>
                    <?php elseif($canManageAgents): ?>
                        <button wire:click="reconnectAgent('<?php echo e($agent->id); ?>')" wire:loading.attr="disabled" wire:target="reconnectAgent('<?php echo e($agent->id); ?>')" class="text-xs text-indigo-600 hover:text-indigo-800 px-2 py-1 border border-indigo-200 rounded">
                            Reconnect
                        </button>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <div class="px-5 py-12 text-center text-sm text-gray-400">
                No agents yet. Click <strong>Add Agent</strong> to connect WhatsApp.
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canManageAgents && $showQrModal): ?>
        <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('whatsapp.qr-pairing-modal', ['reconnectAgentId' => $reconnectAgentId]);

$__key = 'qr-modal-' . $modalNonce;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3201110181-0', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /var/www/resources/views/livewire/whatsapp/agent-list.blade.php ENDPATH**/ ?>