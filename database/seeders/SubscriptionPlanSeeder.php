<?php

namespace Database\Seeders;

use App\Modules\Subscription\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name'       => 'Starter',
                'slug'       => 'starter',
                'max_agents' => 1,
                'price'      => 299000,
                'sort_order' => 1,
            ],
            [
                'name'       => 'Growth',
                'slug'       => 'growth',
                'max_agents' => 3,
                'price'      => 599000,
                'sort_order' => 2,
            ],
            [
                'name'       => 'Pro',
                'slug'       => 'pro',
                'max_agents' => 5,
                'price'      => 999000,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::firstOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
