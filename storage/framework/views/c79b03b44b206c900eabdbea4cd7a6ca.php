<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <title><?php echo e(trim($__env->yieldContent('title')) !== '' ? $__env->yieldContent('title') : ($title ?? config('app.name'))); ?></title>
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
    <?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles(); ?>

</head>
<body class="bg-gray-50 text-gray-900 antialiased">
<?php
    $currentUser = auth()->user();
    $tenantContext = app(\App\Modules\Tenancy\Services\TenantContext::class);
    $tenantId = $tenantContext->isSet() ? $tenantContext->getTenantId() : null;
    $tenantRole = $currentUser && $tenantId ? $currentUser->tenantRole($tenantId) : null;
?>

<div class="flex h-screen overflow-hidden">

    
    <aside class="w-64 flex-shrink-0 bg-white border-r border-gray-200 flex flex-col">
        <div class="h-16 flex items-center px-6 border-b border-gray-200">
            <span class="text-lg font-bold text-indigo-600"><?php echo e(config('app.name')); ?></span>
        </div>

        <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
            <a href="<?php echo e(route('dashboard')); ?>"
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium <?php echo e(request()->routeIs('dashboard') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-100'); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                Dashboard
            </a>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($currentUser?->hasTenantPermission('view-leads', $tenantId)): ?>
                <a href="<?php echo e(route('leads.index')); ?>"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium <?php echo e(request()->routeIs('leads.*') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-100'); ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Leads
                </a>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($currentUser?->hasTenantPermission('manage-agents', $tenantId)): ?>
                <a href="<?php echo e(route('whatsapp-agents.index')); ?>"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium <?php echo e(request()->routeIs('whatsapp-agents.*') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-100'); ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                    WhatsApp Agents
                </a>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($currentUser?->hasTenantPermission('manage-knowledge', $tenantId)): ?>
                <a href="<?php echo e(route('knowledge.index')); ?>"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium <?php echo e(request()->routeIs('knowledge.*') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-100'); ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    Knowledge
                </a>

                <a href="<?php echo e(route('booking-schema.index')); ?>"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium <?php echo e(request()->routeIs('booking-schema.*') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-100'); ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    Booking Schema
                </a>

                <a href="<?php echo e(route('pricelists.index')); ?>"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium <?php echo e(request()->routeIs('pricelists.*') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-100'); ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7V6a2 2 0 012-2h6a2 2 0 012 2v1m-9 4h10m-10 4h10m-11 5h12a2 2 0 002-2V9a2 2 0 00-2-2H7a2 2 0 00-2 2v9a2 2 0 002 2z"/></svg>
                    Pricelist PDFs
                </a>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($currentUser?->hasTenantPermission('manage-invoices', $tenantId)): ?>
                <a href="<?php echo e(route('invoices.list')); ?>"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium <?php echo e(request()->routeIs('invoices.list') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-100'); ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Invoices
                </a>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($currentUser?->hasTenantPermission('manage-billing', $tenantId)): ?>
                <a href="<?php echo e(route('billing.index')); ?>"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium <?php echo e(request()->routeIs('billing.*') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-100'); ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    Billing
                </a>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tenantRole === \App\Modules\Auth\Enums\TenantUserRole::VendorAdmin): ?>
                <a href="<?php echo e(route('tenant-profile.edit')); ?>"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium <?php echo e(request()->routeIs('tenant-profile.*') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-100'); ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A9 9 0 1118.88 17.8M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Profile
                </a>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </nav>

        <div class="p-4 border-t border-gray-200 space-y-3">
            <div>
                <div class="text-sm font-medium text-gray-900"><?php echo e($currentUser?->name); ?></div>
                <div class="text-xs text-gray-500"><?php echo e($tenantRole?->label() ?? 'Tenant User'); ?></div>
            </div>
            <form method="POST" action="<?php echo e(route('auth.logout')); ?>">
                <?php echo csrf_field(); ?>
                <button type="submit" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50 hover:text-gray-900">
                    Logout
                </button>
            </form>
        </div>
    </aside>

    
    <div class="flex-1 flex flex-col overflow-hidden">

        
        <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-6 flex-shrink-0">
            <div class="text-sm text-gray-500">
                <?php echo e($tenantContext->isSet() ? $tenantContext->get()->name : ''); ?>

            </div>
            <div class="flex items-center gap-4">
                <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('dashboard.notification-bell');

$__key = null;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2999907725-0', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
            </div>
        </header>

        
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tenantContext->isSet()): ?>
            <?php echo $__env->make('components.subscription-alert', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('success')): ?>
            <div class="mx-6 mt-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm">
                <?php echo e(session('success')); ?>

            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('error')): ?>
            <div class="mx-6 mt-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">
                <?php echo e(session('error')); ?>

            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        
        <main class="flex-1 overflow-y-auto p-6">
            <?php if (! empty(trim($__env->yieldContent('content')))): ?>
                <?php echo $__env->yieldContent('content'); ?>
            <?php else: ?>
                <?php echo e($slot ?? ''); ?>

            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </main>

    </div>
</div>

<div id="global-loading-overlay" class="hidden fixed inset-0 z-[80] bg-slate-950/30 backdrop-blur-sm">
    <div class="flex h-full items-center justify-center">
        <div class="flex items-center gap-3 rounded-2xl bg-white px-5 py-4 shadow-xl ring-1 ring-slate-200">
            <div class="h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent"></div>
            <div>
                <p class="text-sm font-semibold text-slate-900">Memproses action...</p>
                <p class="text-xs text-slate-500">Mohon tunggu sebentar.</p>
            </div>
        </div>
    </div>
</div>

<?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::scripts(); ?>

</body>
</html>
<?php /**PATH /var/www/resources/views/layouts/app.blade.php ENDPATH**/ ?>