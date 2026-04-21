<?php $__env->startSection('title', 'Master Layanan'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <h1 class="text-xl font-semibold text-gray-900">Master Layanan</h1>
        <p class="mt-2 text-sm text-gray-500">
            Tambahkan daftar layanan utama yang nantinya bisa dipilih tenant di halaman profile.
        </p>

        <form method="POST" action="<?php echo e(route('superadmin.services.store')); ?>" class="mt-6 grid gap-4 md:grid-cols-[1.5fr_180px_auto]">
            <?php echo csrf_field(); ?>
            <div>
                <label for="name" class="mb-2 block text-sm font-medium text-gray-700">Nama Layanan</label>
                <input id="name" name="name" type="text" class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm" placeholder="Contoh: Dokumentasi" required>
            </div>
            <div>
                <label for="sort_order" class="mb-2 block text-sm font-medium text-gray-700">Urutan</label>
                <input id="sort_order" name="sort_order" type="number" min="0" value="0" class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-xl bg-gray-900 px-4 py-3 text-sm font-medium text-white hover:bg-gray-800">
                    Tambah
                </button>
            </div>
        </form>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-6 py-4">
            <h2 class="text-sm font-semibold text-gray-900">Daftar Layanan</h2>
        </div>

        <div class="divide-y divide-gray-100">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $services; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <form method="POST" action="<?php echo e(route('superadmin.services.update', $service)); ?>" class="grid gap-4 px-6 py-4 md:grid-cols-[1.5fr_1fr_140px_auto] md:items-end">
                    <?php echo csrf_field(); ?>
                    <div>
                        <label class="mb-2 block text-xs font-medium uppercase tracking-wide text-gray-500">Nama</label>
                        <input name="name" type="text" value="<?php echo e($service->name); ?>" class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm">
                    </div>
                    <div>
                        <label class="mb-2 block text-xs font-medium uppercase tracking-wide text-gray-500">Slug</label>
                        <div class="rounded-xl border border-gray-100 bg-gray-50 px-4 py-3 text-sm text-gray-600"><?php echo e($service->slug); ?></div>
                    </div>
                    <div>
                        <label class="mb-2 block text-xs font-medium uppercase tracking-wide text-gray-500">Urutan</label>
                        <input name="sort_order" type="number" min="0" value="<?php echo e($service->sort_order); ?>" class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm">
                    </div>
                    <div class="flex flex-col gap-3 md:items-end">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-600">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" <?php if($service->is_active): echo 'checked'; endif; ?> class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            Aktif
                        </label>
                        <button type="submit" class="rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Simpan
                        </button>
                    </div>
                </form>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <div class="px-6 py-10 text-sm text-gray-500">
                    Belum ada master layanan.
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.superadmin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/resources/views/superadmin/services/index.blade.php ENDPATH**/ ?>