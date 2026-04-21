<?php

namespace App\Modules\Billing\Jobs;

use App\Modules\Billing\Services\RenewalGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateRenewalInvoicesJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(RenewalGenerationService $service): void
    {
        $service->generateUpcomingRenewals();
    }
}
