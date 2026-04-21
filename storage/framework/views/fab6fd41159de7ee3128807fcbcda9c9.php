<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-bold">Knowledge Base</h1>
    </div>

    
    <div class="flex gap-1 mb-4 border-b border-gray-200">
        <button wire:click="$set('activeTab', 'items')"
                class="px-4 py-2 text-sm font-medium border-b-2 -mb-px <?php echo e($activeTab === 'items' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500'); ?>">
            Knowledge Items
        </button>
        <button wire:click="$set('activeTab', 'candidates')"
                class="px-4 py-2 text-sm font-medium border-b-2 -mb-px <?php echo e($activeTab === 'candidates' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500'); ?>">
            Candidates <span class="ml-1 text-xs bg-gray-100 px-1.5 rounded-full"><?php echo e($candidates->total()); ?></span>
        </button>
    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($activeTab === 'items'): ?>
        <div class="flex items-center gap-3 mb-4">
            <select wire:model.live="typeFilter" class="border border-gray-200 rounded-lg px-3 py-2 text-sm">
                <option value="">All Types</option>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $types; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $type): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($type->value); ?>"><?php echo e($type->value); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </select>
            <button wire:click="openCreateForm" wire:loading.attr="disabled" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">
                + Add Item
            </button>
        </div>

        <div class="bg-white rounded-xl border border-gray-200">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Title</th>
                            <th class="px-5 py-3 text-left">Type</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3">
                                    <p class="font-medium"><?php echo e($item->title); ?></p>
                                    <p class="text-xs text-gray-400 line-clamp-1"><?php echo e(Str::limit($item->content, 80)); ?></p>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="px-2 py-0.5 bg-blue-50 text-blue-700 text-xs rounded-full"><?php echo e($item->type->value); ?></span>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="px-2 py-0.5 text-xs rounded-full <?php echo e($item->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'); ?>">
                                        <?php echo e($item->is_active ? 'Active' : 'Inactive'); ?>

                                    </span>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2">
                                        <button wire:click="openEditForm(<?php echo e($item->id); ?>)" wire:loading.attr="disabled" wire:target="openEditForm(<?php echo e($item->id); ?>)" class="text-xs text-gray-500 hover:text-indigo-600">Edit</button>
                                        <button wire:click="toggleActive(<?php echo e($item->id); ?>)" wire:loading.attr="disabled" wire:target="toggleActive(<?php echo e($item->id); ?>)" class="text-xs text-gray-500 hover:text-orange-600">
                                            <?php echo e($item->is_active ? 'Deactivate' : 'Activate'); ?>

                                        </button>
                                        <button wire:click="deleteItem(<?php echo e($item->id); ?>)"
                                                wire:loading.attr="disabled"
                                                wire:target="deleteItem(<?php echo e($item->id); ?>)"
                                                wire:confirm="Delete this item?"
                                                class="text-xs text-red-400 hover:text-red-600">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                            <tr>
                                <td colspan="4" class="px-5 py-10 text-center text-sm text-gray-400">No knowledge items</td>
                            </tr>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-3 border-t border-gray-100"><?php echo e($items->links()); ?></div>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($activeTab === 'candidates'): ?>
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Proposed Title</th>
                            <th class="px-5 py-3 text-left">Type</th>
                            <th class="px-5 py-3 text-left">Source</th>
                            <th class="px-5 py-3 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $candidates; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $candidate): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 font-medium"><?php echo e($candidate->proposed_title); ?></td>
                                <td class="px-5 py-3">
                                    <span class="px-2 py-0.5 bg-purple-50 text-purple-700 text-xs rounded-full"><?php echo e($candidate->proposed_type); ?></span>
                                </td>
                                <td class="px-5 py-3 text-xs text-gray-400"><?php echo e($candidate->source_note); ?></td>
                                <td class="px-5 py-3">
                                    <div class="flex gap-2">
                                        <button wire:click="approveCandidate(<?php echo e($candidate->id); ?>)"
                                                wire:loading.attr="disabled"
                                                wire:target="approveCandidate(<?php echo e($candidate->id); ?>)"
                                                class="text-xs px-2 py-1 bg-green-100 text-green-700 rounded hover:bg-green-200">Approve</button>
                                        <button wire:click="rejectCandidate(<?php echo e($candidate->id); ?>)"
                                                wire:loading.attr="disabled"
                                                wire:target="rejectCandidate(<?php echo e($candidate->id); ?>)"
                                                class="text-xs px-2 py-1 bg-red-100 text-red-700 rounded hover:bg-red-200">Reject</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                            <tr>
                                <td colspan="4" class="px-5 py-10 text-center text-sm text-gray-400">No candidates</td>
                            </tr>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-3 border-t border-gray-100"><?php echo e($candidates->links()); ?></div>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showForm): ?>
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg mx-4 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold"><?php echo e($editingId ? 'Edit' : 'Add'); ?> Knowledge Item</h2>
                    <button wire:click="$set('showForm', false)" class="text-gray-400 hover:text-gray-600">✕</button>
                </div>
                <div class="space-y-3">
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">Title</label>
                        <input wire:model="formTitle" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">Type</label>
                        <select wire:model="formType" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="">Select type</option>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $types; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $type): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <option value="<?php echo e($type->value); ?>"><?php echo e($type->value); ?></option>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">Content</label>
                        <textarea wire:model="formContent" rows="5"
                                  class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300"></textarea>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input wire:model="formActive" type="checkbox" class="rounded">
                        Active
                    </label>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button wire:click="$set('showForm', false)"
                            wire:loading.attr="disabled"
                            wire:target="saveItem"
                            class="px-4 py-2 text-sm border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button wire:click="saveItem"
                            wire:loading.attr="disabled"
                            wire:target="saveItem"
                            class="px-4 py-2 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Save</button>
                </div>
            </div>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /var/www/resources/views/livewire/knowledge/knowledge-page.blade.php ENDPATH**/ ?>