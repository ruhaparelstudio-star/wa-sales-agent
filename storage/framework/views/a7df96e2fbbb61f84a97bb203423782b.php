<?php
    $alertService = app(\App\Modules\Dashboard\Services\AlertService::class);
    $tenant = app(\App\Modules\Tenancy\Services\TenantContext::class)->get();
    $level  = $alertService->getSubscriptionAlertLevel($tenant);
    $message = $alertService->getSubscriptionAlertMessage($tenant);
?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($level !== null): ?>
    <div class="px-6 py-2 text-sm font-medium
        <?php if($level === 'critical'): ?> bg-red-600 text-white
        <?php elseif($level === 'danger'): ?> bg-orange-500 text-white
        <?php else: ?> bg-yellow-100 text-yellow-800 border-b border-yellow-200
        <?php endif; ?>">
        <?php echo e($message); ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($level !== 'warning'): ?>
            <a href="<?php echo e(route('billing.index')); ?>" class="underline ml-2">View Billing</a>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /var/www/resources/views/components/subscription-alert.blade.php ENDPATH**/ ?>