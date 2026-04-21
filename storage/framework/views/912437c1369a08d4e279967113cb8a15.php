<div>
    <?php
        $tenantContext = app(\App\Modules\Tenancy\Services\TenantContext::class);
        $tenantId = $tenantContext->isSet() ? $tenantContext->getTenantId() : null;
        $canManageLeads = $tenantId !== null
            && auth()->user()?->hasTenantPermission('manage-leads', $tenantId);
    ?>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-bold">Leads</h1>
        <span class="text-sm text-gray-400"><?php echo e($leads->total()); ?> total</span>
    </div>

    
    <div class="flex flex-wrap gap-3 mb-4">
        <input wire:model.live.debounce.300ms="search"
               type="text"
               placeholder="Search name or number..."
               class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-56 focus:outline-none focus:ring-2 focus:ring-indigo-300">

        <select wire:model.live="statusFilter" class="border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <option value="">All Statuses</option>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $statuses; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $status): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($status->value); ?>"><?php echo e($status->value); ?></option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </select>
    </div>

    
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $leads; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $lead): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3">
                                <p class="font-medium"><?php echo e($lead->name ?? '—'); ?></p>
                                <p class="text-xs text-gray-400"><?php echo e($lead->phone_e164); ?></p>
                            </td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium
                                    <?php echo e(match($lead->status->value) {
                                        'HOT', 'READY_FOR_HUMAN' => 'bg-red-100 text-red-700',
                                        'QUALIFIED', 'INTERESTED' => 'bg-blue-100 text-blue-700',
                                        'NEW' => 'bg-gray-100 text-gray-600',
                                        default => 'bg-gray-100 text-gray-500'
                                    }); ?>">
                                    <?php echo e($lead->status->value); ?>

                                </span>
                            </td>
                            <td class="px-5 py-3 text-gray-400 text-xs">
                                <?php echo e($lead->last_message_at?->diffForHumans() ?? '—'); ?>

                            </td>
                            <td class="px-5 py-3 text-xs text-gray-500">
                                <?php echo e($lead->whatsappAgent?->phone_number ?? '—'); ?>

                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <a href="<?php echo e(route('leads.show', $lead)); ?>"
                                       class="text-xs text-indigo-600 hover:underline">Detail</a>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canManageLeads): ?>
                                        <button wire:click="toggleAutomation(<?php echo e($lead->id); ?>)"
                                                class="text-xs <?php echo e($lead->automation_paused ? 'text-green-600' : 'text-orange-500'); ?> hover:underline">
                                            <?php echo e($lead->automation_paused ? 'Resume' : 'Pause'); ?>

                                        </button>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-sm text-gray-400">No leads found</td>
                        </tr>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="px-5 py-3 border-t border-gray-100">
            <?php echo e($leads->links()); ?>

        </div>
    </div>
</div>
<?php /**PATH /var/www/resources/views/livewire/leads/lead-list.blade.php ENDPATH**/ ?>