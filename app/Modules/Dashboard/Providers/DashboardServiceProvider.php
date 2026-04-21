<?php

namespace App\Modules\Dashboard\Providers;

use App\Modules\Billing\Http\Livewire\BillingPage;
use App\Modules\Booking\Http\Livewire\SchemaPage;
use App\Modules\Dashboard\Http\Livewire\NotificationBell;
use App\Modules\Dashboard\Http\Livewire\VendorDashboard;
use App\Modules\Invoice\Http\Livewire\InvoiceList;
use App\Modules\Knowledge\Http\Livewire\KnowledgePage;
use App\Modules\Knowledge\Http\Livewire\PricelistPage;
use App\Modules\Leads\Http\Livewire\LeadDetail;
use App\Modules\Leads\Http\Livewire\LeadList;
use App\Modules\WhatsApp\Http\Livewire\AgentList;
use App\Modules\WhatsApp\Http\Livewire\QrPairingModal;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class DashboardServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('dashboard.vendor-dashboard', VendorDashboard::class);
        Livewire::component('dashboard.notification-bell', NotificationBell::class);
        Livewire::component('whatsapp.agent-list', AgentList::class);
        Livewire::component('whatsapp.qr-pairing-modal', QrPairingModal::class);
        Livewire::component('billing.billing-page', BillingPage::class);
        Livewire::component('leads.lead-list', LeadList::class);
        Livewire::component('leads.lead-detail', LeadDetail::class);
        Livewire::component('knowledge.knowledge-page', KnowledgePage::class);
        Livewire::component('knowledge.pricelist-page', PricelistPage::class);
        Livewire::component('booking.schema-page', SchemaPage::class);
        Livewire::component('invoice.invoice-list', InvoiceList::class);
    }
}
