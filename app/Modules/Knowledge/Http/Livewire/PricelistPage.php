<?php

namespace App\Modules\Knowledge\Http\Livewire;

use App\Modules\Knowledge\Services\PricelistService;
use App\Modules\Tenancy\Services\TenantContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Pricelist PDFs')]
class PricelistPage extends Component
{
    public function render(TenantContext $tenantContext, PricelistService $service)
    {
        $tenant = $tenantContext->get();

        return view('livewire.knowledge.pricelist-page', [
            'files' => $service->listFiles($tenant),
            'directory' => $service->directory($tenant),
        ]);
    }
}
