<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Billing\Http\Controllers\BillingController;
use App\Modules\Billing\Http\Controllers\SuperAdminBillingController;
use App\Modules\Billing\Http\Controllers\SuperAdminUsageController;
use App\Modules\Invoice\Http\Controllers\ClientInvoiceController;
use App\Modules\Knowledge\Http\Controllers\PricelistController;
use App\Modules\Leads\Http\Livewire\LeadDetail;
use App\Modules\Leads\Http\Livewire\LeadList;
use App\Modules\Subscription\Http\Controllers\SuperAdminSubscriptionController;
use App\Modules\Tenancy\Http\Controllers\SuperAdminTenantController;
use App\Modules\Tenancy\Http\Controllers\SuperAdminServiceCatalogController;
use App\Modules\Tenancy\Http\Controllers\ActivateInvitationController;
use App\Modules\Tenancy\Http\Controllers\TenantProfileController;
use App\Modules\WhatsApp\Http\Controllers\PairingApiController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Auth routes
Route::prefix('auth')->name('auth.')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

    Route::get('/activate', [ActivateInvitationController::class, 'show'])->name('activate');
    Route::post('/activate', [ActivateInvitationController::class, 'store'])->name('activate.submit');
});

// Vendor admin routes
Route::middleware(['auth', 'tenant'])->group(function () {
    // Dashboard
    Route::get('/dashboard', \App\Modules\Dashboard\Http\Livewire\VendorDashboard::class)->name('dashboard');

    // WhatsApp agents
    Route::prefix('whatsapp-agents')->name('whatsapp-agents.')->group(function () {
        Route::get('/', \App\Modules\WhatsApp\Http\Livewire\AgentList::class)->name('index');
    });

    // Pairing SSE (API-style but within tenant auth)
    Route::prefix('api/whatsapp/pairing')->name('whatsapp.pairing.')->group(function () {
        Route::get('/{id}/sse', [PairingApiController::class, 'stream'])->name('sse');
        Route::post('/{id}/cancel', [PairingApiController::class, 'cancel'])->name('cancel');
    });

    // Billing
    Route::prefix('billing')->name('billing.')->group(function () {
        Route::get('/', \App\Modules\Billing\Http\Livewire\BillingPage::class)->name('index');
        Route::post('/{id}/proof', [BillingController::class, 'uploadProof'])->name('proof.upload');
    });

    // Leads
    Route::prefix('leads')->name('leads.')->group(function () {
        Route::get('/', \App\Modules\Leads\Http\Livewire\LeadList::class)->name('index');
        Route::get('/{leadId}', \App\Modules\Leads\Http\Livewire\LeadDetail::class)->name('show');
    });

    // Invoices list page
    Route::get('/invoices', \App\Modules\Invoice\Http\Livewire\InvoiceList::class)->name('invoices.list');

    // Invoice CRUD (per-lead REST endpoints)
    Route::prefix('leads/{lead}/invoices')->name('invoices.')->group(function () {
        Route::get('/', [ClientInvoiceController::class, 'index'])->name('index');
        Route::post('/', [ClientInvoiceController::class, 'store'])->name('store');
        Route::post('/upload', [ClientInvoiceController::class, 'upload'])->name('upload');
        Route::get('/{id}', [ClientInvoiceController::class, 'show'])->name('show');
        Route::post('/{id}/send', [ClientInvoiceController::class, 'send'])->name('send');
    });

    // Knowledge
    Route::get('/knowledge', \App\Modules\Knowledge\Http\Livewire\KnowledgePage::class)->name('knowledge.index');
    Route::get('/pricelists', \App\Modules\Knowledge\Http\Livewire\PricelistPage::class)->name('pricelists.index');
    Route::post('/pricelists', [PricelistController::class, 'store'])->name('pricelists.store');
    Route::get('/pricelists/{filename}/download', [PricelistController::class, 'download'])->name('pricelists.download');
    Route::delete('/pricelists/{filename}', [PricelistController::class, 'destroy'])->name('pricelists.destroy');

    // Booking schema
    Route::get('/booking-schema', \App\Modules\Booking\Http\Livewire\SchemaPage::class)->name('booking-schema.index');
    Route::get('/profile', [TenantProfileController::class, 'edit'])->name('tenant-profile.edit');
    Route::post('/profile', [TenantProfileController::class, 'update'])->name('tenant-profile.update');
});

// Super admin routes
Route::prefix('superadmin')->name('superadmin.')->middleware(['auth', 'super_admin'])->group(function () {
    Route::get('/tenants', [SuperAdminTenantController::class, 'index'])->name('tenants.index');
    Route::get('/tenants/create', [SuperAdminTenantController::class, 'create'])->name('tenants.create');
    Route::post('/tenants', [SuperAdminTenantController::class, 'store'])->name('tenants.store');
    Route::get('/tenants/{id}', [SuperAdminTenantController::class, 'show'])->name('tenants.show');
    Route::get('/services', [SuperAdminServiceCatalogController::class, 'index'])->name('services.index');
    Route::post('/services', [SuperAdminServiceCatalogController::class, 'store'])->name('services.store');
    Route::post('/services/{serviceCatalog}', [SuperAdminServiceCatalogController::class, 'update'])->name('services.update');

    Route::prefix('billing')->name('billing.')->group(function () {
        Route::get('/', [SuperAdminBillingController::class, 'index'])->name('index');
        Route::post('/{id}/approve', [SuperAdminBillingController::class, 'approve'])->name('approve');
    });

    Route::prefix('subscriptions')->name('subscriptions.')->group(function () {
        Route::post('/assign', [SuperAdminSubscriptionController::class, 'assign'])->name('assign');
    });

    Route::get('/usage', [SuperAdminUsageController::class, 'index'])->name('usage.index');
});
