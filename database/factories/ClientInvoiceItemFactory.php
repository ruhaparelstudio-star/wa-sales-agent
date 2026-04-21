<?php

namespace Database\Factories;

use App\Modules\Invoice\Models\ClientInvoice;
use App\Modules\Invoice\Models\ClientInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientInvoiceItemFactory extends Factory
{
    protected $model = ClientInvoiceItem::class;

    public function definition(): array
    {
        $qty        = $this->faker->numberBetween(1, 5);
        $unitPrice  = $this->faker->randomFloat(2, 100000, 5000000);

        return [
            'invoice_id'  => ClientInvoice::factory(),
            'description' => $this->faker->words(3, true),
            'quantity'    => $qty,
            'unit_price'  => $unitPrice,
            'total_price' => $qty * $unitPrice,
            'sort_order'  => 0,
        ];
    }
}
