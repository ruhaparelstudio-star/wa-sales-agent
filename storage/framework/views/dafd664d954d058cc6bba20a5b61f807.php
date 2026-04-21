<?php $__env->startSection('title', 'Profile Tenant'); ?>

<?php $__env->startSection('content'); ?>
    <?php
        $tenantId = app(\App\Modules\Tenancy\Services\TenantContext::class)->getTenantId();
        $isVendorAdmin = auth()->user()?->tenantRole($tenantId) === \App\Modules\Auth\Enums\TenantUserRole::VendorAdmin;
    ?>

    <div class="max-w-3xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Profile Tenant</h1>
            <p class="mt-2 text-sm text-gray-500">
                Tentukan layanan utama tenant yang akan dipakai AI sebagai konteks default. User tidak akan lagi ditanya layanan di tahap awal.
            </p>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <dl class="mb-6 space-y-3 text-sm">
                <div class="flex gap-3">
                    <dt class="w-40 text-gray-400">Nama Tenant</dt>
                    <dd class="font-medium text-gray-900"><?php echo e($tenant->name); ?></dd>
                </div>
                <div class="flex gap-3">
                    <dt class="w-40 text-gray-400">Slug</dt>
                    <dd class="font-mono text-gray-700"><?php echo e($tenant->slug); ?></dd>
                </div>
                <div class="flex gap-3">
                    <dt class="w-40 text-gray-400">Layanan Utama</dt>
                    <dd class="text-gray-900"><?php echo e($tenant->primaryServiceName() ?? 'Belum diatur'); ?></dd>
                </div>
            </dl>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(! $isVendorAdmin): ?>
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Hanya vendor admin yang bisa mengubah profile tenant.
                </div>
            <?php else: ?>
                <form method="POST" action="<?php echo e(route('tenant-profile.update')); ?>" class="space-y-5">
                    <?php echo csrf_field(); ?>
                    <div>
                        <label for="primary_service_catalog_id" class="mb-2 block text-sm font-medium text-gray-700">
                            Layanan Utama
                        </label>
                        <select
                            id="primary_service_catalog_id"
                            name="primary_service_catalog_id"
                            class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                        >
                            <option value="">Pilih layanan utama</option>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $services; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <option value="<?php echo e($service->id); ?>" <?php if(old('primary_service_catalog_id', $tenant->primary_service_catalog_id) == $service->id): echo 'selected'; endif; ?>>
                                    <?php echo e($service->name); ?>

                                </option>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </select>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['primary_service_catalog_id'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                            <p class="mt-2 text-sm text-red-600"><?php echo e($message); ?></p>
                        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <p class="mt-2 text-xs text-gray-500">
                            Daftar ini diatur dari halaman super admin. Tenant tidak bisa mengetik layanan manual.
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-700">
                            Simpan Profile
                        </button>
                    </div>
                </form>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/resources/views/tenant/profile/edit.blade.php ENDPATH**/ ?>