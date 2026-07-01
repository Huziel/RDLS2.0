<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SubscriptionPlan;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        SubscriptionPlan::firstOrCreate(['name' => 'Gratuito'], [
            'price_percent' => 0,
            'max_products' => 10,
            'modules' => ['products', 'orders'],
            'is_default' => true,
            'active' => true,
        ]);

        SubscriptionPlan::firstOrCreate(['name' => 'Premium'], [
            'price_percent' => 5.00,
            'max_products' => null,
            'modules' => ['products', 'orders', 'coupons', 'pos', 'delivery', 'crm', 'qr', 'appointments', 'barters', 'media', 'chat', 'loyalty', 'ad-generator', 'analytics'],
            'is_default' => false,
            'active' => true,
        ]);
    }
}
