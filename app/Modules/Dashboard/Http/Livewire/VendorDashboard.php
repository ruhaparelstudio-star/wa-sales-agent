<?php

namespace App\Modules\Dashboard\Http\Livewire;

use App\Modules\Dashboard\ViewModels\DashboardViewModel;
use App\Modules\Tenancy\Services\TenantContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Dashboard')]
class VendorDashboard extends Component
{
    public function render(DashboardViewModel $viewModel, TenantContext $tenantContext)
    {
        $data = $viewModel->forTenant($tenantContext->get());

        return view('livewire.dashboard.vendor-dashboard', $data);
    }
}
