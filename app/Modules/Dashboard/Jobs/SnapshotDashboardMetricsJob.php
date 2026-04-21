<?php

namespace App\Modules\Dashboard\Jobs;

use App\Modules\Dashboard\Services\DashboardMetricsService;
use App\Modules\Subscription\Enums\SubscriptionStatus;
use App\Modules\Subscription\Models\Subscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SnapshotDashboardMetricsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(DashboardMetricsService $metrics): void
    {
        // Pre-warm cache for all tenants with active subscriptions
        Subscription::where('status', SubscriptionStatus::Active)
            ->with('tenant')
            ->get()
            ->each(function (Subscription $sub) use ($metrics) {
                if (! $sub->tenant) {
                    return;
                }

                // Flush stale cache then recalculate (stores fresh data for 35 min)
                $metrics->flushCache($sub->tenant_id);
                $metrics->getMetrics($sub->tenant);

                Log::debug('[SnapshotDashboardMetrics] Refreshed', ['tenant_id' => $sub->tenant_id]);
            });
    }
}
