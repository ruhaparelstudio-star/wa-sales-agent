<?php

namespace App\Modules\Billing\Jobs;

use App\Modules\Auth\Services\TenantMembershipService;
use App\Modules\Billing\Notifications\BillingAlertNotification;
use App\Modules\Subscription\Enums\SubscriptionStatus;
use App\Modules\Subscription\Models\Subscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendBillingAlertsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    private const ALERT_DAYS = [7, 3, 1, 0, -1];

    public function __construct()
    {
        $this->onQueue('medium');
    }

    public function handle(TenantMembershipService $membership): void
    {
        foreach (self::ALERT_DAYS as $days) {
            $this->processDay($days, $membership);
        }
    }

    private function processDay(int $days, TenantMembershipService $membership): void
    {
        $targetDate = $days >= 0
            ? now()->addDays($days)->toDateString()
            : now()->subDays(abs($days))->toDateString();

        Subscription::whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::GracePeriod, SubscriptionStatus::Expired])
            ->whereDate('ends_at', $targetDate)
            ->with(['tenant', 'plan'])
            ->each(function (Subscription $sub) use ($days, $membership) {
                $cacheKey = "billing_alert:{$sub->id}:{$days}";

                // Idempotency: skip if already sent today (TTL 23 hours)
                if (Cache::has($cacheKey)) {
                    return;
                }

                $admins = $membership->getAdminsForTenant($sub->tenant);

                if ($admins->isEmpty()) {
                    return;
                }

                $notification = new BillingAlertNotification($sub, $days);

                foreach ($admins as $admin) {
                    $admin->notify($notification);
                }

                Cache::put($cacheKey, true, now()->addHours(23));

                Log::info('[SendBillingAlertsJob] Alert sent', [
                    'subscription_id' => $sub->id,
                    'tenant_id'       => $sub->tenant_id,
                    'days_remaining'  => $days,
                ]);
            });
    }
}
