<?php

namespace Database\Factories;

use App\Modules\Billing\Enums\BillingInvoiceStatus;
use App\Modules\Billing\Models\BillingInvoice;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class BillingInvoiceFactory extends Factory
{
    protected $model = BillingInvoice::class;

    public function definition(): array
    {
        $periodStart = now()->startOfMonth();

        return [
            'tenant_id'       => Tenant::factory(),
            'subscription_id' => null,
            'invoice_number'  => 'INV-' . now()->format('Ym') . '-' . str_pad($this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'amount'          => 299000,
            'status'          => BillingInvoiceStatus::Unpaid,
            'due_date'        => now()->addDays(7)->toDateString(),
            'period_start'    => $periodStart->toDateString(),
            'period_end'      => $periodStart->copy()->addMonth()->toDateString(),
        ];
    }

    public function paid(): static
    {
        return $this->state([
            'status'      => BillingInvoiceStatus::Paid,
            'approved_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => BillingInvoiceStatus::Cancelled]);
    }
}
