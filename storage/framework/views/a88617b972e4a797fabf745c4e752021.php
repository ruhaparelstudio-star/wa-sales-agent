<div>
    
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs text-gray-500 mb-1">Hot Leads</p>
            <p class="text-3xl font-bold text-red-600"><?php echo e($metrics['hot_leads_count']); ?></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs text-gray-500 mb-1">Pending Handoffs</p>
            <p class="text-3xl font-bold text-orange-500"><?php echo e($metrics['pending_handoffs_count']); ?></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs text-gray-500 mb-1">Agent Slots</p>
            <p class="text-3xl font-bold text-indigo-600"><?php echo e($metrics['agent_slots_used']); ?>/<?php echo e($metrics['agent_slots_max']); ?></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs text-gray-500 mb-1">Days Remaining</p>
            <p class="text-3xl font-bold <?php echo e($metrics['subscription_days_remaining'] <= 7 ? 'text-red-500' : 'text-green-600'); ?>">
                <?php echo e($metrics['subscription_days_remaining']); ?>

            </p>
        </div>
    </div>

    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($metrics['unpaid_billing_count'] > 0): ?>
        <div class="mb-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-800">
            You have <strong><?php echo e($metrics['unpaid_billing_count']); ?></strong> unpaid billing invoice(s).
            <a href="<?php echo e(route('billing.index')); ?>" class="underline ml-1">View Billing</a>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold">Hot Leads</h3>
                <a href="<?php echo e(route('leads.index')); ?>" class="text-xs text-indigo-600 hover:underline">View all</a>
            </div>
            <div class="divide-y divide-gray-50">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $hot_leads; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $lead): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <a href="<?php echo e(route('leads.show', $lead)); ?>" class="flex items-center justify-between px-4 py-3 hover:bg-gray-50 text-sm">
                        <div>
                            <p class="font-medium"><?php echo e($lead->name ?? $lead->phone_e164); ?></p>
                            <p class="text-xs text-gray-400"><?php echo e($lead->status->value); ?></p>
                        </div>
                        <span class="text-xs text-gray-400"><?php echo e($lead->last_message_at?->diffForHumans()); ?></span>
                    </a>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <p class="px-4 py-6 text-sm text-gray-400 text-center">No hot leads</p>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>

        
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-4 py-3 border-b border-gray-100">
                <h3 class="text-sm font-semibold">Pending Handoffs</h3>
            </div>
            <div class="divide-y divide-gray-50">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $pending_handoffs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $handoff): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <a href="<?php echo e(route('leads.show', $handoff->lead)); ?>" class="flex items-center justify-between px-4 py-3 hover:bg-gray-50 text-sm">
                        <div>
                            <p class="font-medium"><?php echo e($handoff->lead->name ?? $handoff->lead->phone_e164); ?></p>
                            <p class="text-xs text-gray-400"><?php echo e($handoff->reason); ?></p>
                        </div>
                        <span class="text-xs text-gray-400"><?php echo e($handoff->created_at->diffForHumans()); ?></span>
                    </a>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <p class="px-4 py-6 text-sm text-gray-400 text-center">No pending handoffs</p>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php /**PATH /var/www/resources/views/livewire/dashboard/vendor-dashboard.blade.php ENDPATH**/ ?>