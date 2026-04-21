<?php $__env->startSection('title', 'Daftar Tenant'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('activation_url')): ?>
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
            <div class="font-medium">Link aktivasi tenant</div>
            <div class="mt-1">Jika email belum masuk, buka link ini langsung untuk aktivasi akun tenant:</div>
            <div class="mt-3 rounded-lg border border-blue-200 bg-white px-3 py-2 font-mono text-xs break-all">
                <?php echo e(session('activation_url')); ?>

            </div>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Daftar Tenant</h1>
            <p class="text-sm text-gray-500 mt-1">Kelola onboarding tenant dan lihat status tenant yang sudah aktif.</p>
        </div>
        <a href="<?php echo e(route('superadmin.tenants.create')); ?>"
           class="inline-flex items-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
            + Buat Tenant
        </a>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-5 py-3">#</th>
                        <th class="px-5 py-3">Nama</th>
                        <th class="px-5 py-3">Slug</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Dibuat</th>
                        <th class="px-5 py-3">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $tenants; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $tenant): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-4 text-gray-500"><?php echo e($tenant->id); ?></td>
                            <td class="px-5 py-4">
                                <div class="font-medium text-gray-900"><?php echo e($tenant->name); ?></div>
                                <div class="text-xs text-gray-500"><?php echo e($tenant->tenantUsers->count()); ?> membership</div>
                            </td>
                            <td class="px-5 py-4 font-mono text-xs text-gray-600"><?php echo e($tenant->slug); ?></td>
                            <td class="px-5 py-4">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium <?php echo e($tenant->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'); ?>">
                                    <?php echo e($tenant->is_active ? 'Aktif' : 'Nonaktif'); ?>

                                </span>
                            </td>
                            <td class="px-5 py-4 text-gray-500"><?php echo e($tenant->created_at->format('d M Y')); ?></td>
                            <td class="px-5 py-4">
                                <a href="<?php echo e(route('superadmin.tenants.show', $tenant->id)); ?>"
                                   class="text-sm font-medium text-indigo-600 hover:text-indigo-700">
                                    Detail
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-400">Belum ada tenant.</td>
                        </tr>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="border-t border-gray-100 px-5 py-3">
            <?php echo e($tenants->links()); ?>

        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.superadmin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/resources/views/superadmin/tenants/index.blade.php ENDPATH**/ ?>