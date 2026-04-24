<div>
    <?php
        $tenantContext = app(\App\Modules\Tenancy\Services\TenantContext::class);
        $tenantId = $tenantContext->isSet() ? $tenantContext->getTenantId() : null;
        $canManageBilling = $tenantId !== null
            && auth()->user()?->hasTenantPermission('manage-billing', $tenantId);
    ?>
    <h1 class="text-xl font-bold mb-6">Billing</h1>

    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs text-gray-500 mb-1">Active Plan</p>
            <p class="text-lg font-bold text-gray-800"><?php echo e($plan?->name ?? '—'); ?></p>
            <p class="text-xs text-gray-400"><?php echo e($subscription ? 'Expires ' . $subscription->ends_at->format('d M Y') : 'No active subscription'); ?></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs text-gray-500 mb-1">Agent Slots</p>
            <p class="text-lg font-bold text-gray-800"><?php echo e($slotsUsed); ?> / <?php echo e($plan?->max_agents ?? '—'); ?></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-xs text-gray-500 mb-1">Plan Price</p>
            <p class="text-lg font-bold text-gray-800">
                Rp <?php echo e($plan ? number_format($plan->price, 0, ',', '.') : '—'); ?>/mo
            </p>
        </div>
    </div>

    
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $invoices; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $invoice): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 font-mono text-xs"><?php echo e($invoice->invoice_number); ?></td>
                            <td class="px-5 py-3 text-gray-500"><?php echo e($invoice->period_start->format('d M')); ?> – <?php echo e($invoice->period_end->format('d M Y')); ?></td>
                            <td class="px-5 py-3 text-right font-medium">Rp <?php echo e(number_format($invoice->amount, 0, ',', '.')); ?></td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium
                                    <?php echo e($invoice->status->value === 'paid' ? 'bg-green-100 text-green-700' : ($invoice->status->value === 'unpaid' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700')); ?>">
                                    <?php echo e($invoice->status->value); ?>

                                </span>
                            </td>
                            <td class="px-5 py-3 text-gray-500"><?php echo e($invoice->due_date->format('d M Y')); ?></td>
                            <td class="px-5 py-3">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canManageBilling && $invoice->status->value === 'unpaid' && !$invoice->proof_path): ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($uploadingInvoiceId === $invoice->id): ?>
                                        <div class="flex items-center gap-2">
                                            <input type="file" wire:model="proofFile" class="text-xs">
                                            <button wire:click="uploadProof" wire:loading.attr="disabled" wire:target="uploadProof,proofFile" class="text-xs px-2 py-1 bg-indigo-600 text-white rounded">Upload</button>
                                            <button wire:click="$set('uploadingInvoiceId', null)" wire:loading.attr="disabled" class="text-xs text-gray-400">Cancel</button>
                                        </div>
                                    <?php else: ?>
                                        <button wire:click="$set('uploadingInvoiceId', <?php echo e($invoice->id); ?>)"
                                                wire:loading.attr="disabled"
                                                class="text-xs text-indigo-600 hover:underline">
                                            Upload Proof
                                        </button>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php elseif($invoice->proof_path && $invoice->status->value === 'unpaid'): ?>
                                    <span class="text-xs text-yellow-600">Awaiting approval</span>
                                <?php else: ?>
                                    <span class="text-xs text-gray-300">—</span>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-400">No billing history</td>
                        </tr>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="px-5 py-3 border-t border-gray-100">
            <?php echo e($invoices->links()); ?>

        </div>
    </div>
</div>
<?php /**PATH /var/www/resources/views/livewire/billing/billing-page.blade.php ENDPATH**/ ?>