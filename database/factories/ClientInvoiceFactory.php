<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Invoice\Enums\ClientInvoiceStatus;
use App\Modules\Invoice\Enums\ClientInvoiceType;
use App\Modules\Invoice\Models\ClientInvoice;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientInvoiceFactory extends Factory
{
    protected $model = ClientInvoice::class;

    public function definition(): array
    {
        return [
            'tenant_id'      => Tenant::factory(),
            'lead_id'        => Lead::factory(),
            'invoice_number' => 'INV-1-' . now()->format('Ym') . '-' . str_pad($this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'invoice_type'   => ClientInvoiceType::Created,
            'status'         => ClientInvoiceStatus::Draft,
            'amount'         => $this->faker->randomFloat(2, 500000, 50000000),
            'currency'       => 'IDR',
            'due_date'       => now()->addDays(7)->toDateString(),
            'pdf_path'       => null,
            'intro_message'  => null,
            'wa_message_id'  => null,
            'sent_at'        => null,
            'delivered_at'   => null,
            'paid_at'        => null,
            'notes'          => null,
            'created_by'     => User::factory(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => ClientInvoiceStatus::Draft]);
    }

    public function sent(): static
    {
        return $this->state([
            'status'  => ClientInvoiceStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    public function delivered(): static
    {
        return $this->state([
            'status'       => ClientInvoiceStatus::Delivered,
            'sent_at'      => now()->subHour(),
            'delivered_at' => now(),
        ]);
    }

    public function paid(): static
    {
        return $this->state([
            'status'  => ClientInvoiceStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function uploaded(): static
    {
        return $this->state([
            'invoice_type' => ClientInvoiceType::Uploaded,
            'pdf_path'     => 'tenants/1/invoices/test.pdf',
        ]);
    }
}
