<?php

namespace Database\Seeders;

use App\Models\Reward;
use Illuminate\Database\Seeder;

/**
 * Sample rewards catalogue. Point requirements are placeholders for
 * administrators to adjust in Reward Redemption > Rewards Catalogue.
 */
class RewardsSeeder extends Seeder
{
    public function run(): void
    {
        $rewards = [
            ['code' => 'GV-100', 'name' => 'PHP 100 Grocery Voucher', 'description' => 'Voucher usable on the next purchase at any ShopLink branch.', 'points_required' => 100],
            ['code' => 'ECO-BAG', 'name' => 'ShopLink Eco Bag', 'description' => 'Reusable shopping bag.', 'points_required' => 50, 'stock_quantity' => 100],
            ['code' => 'GV-500', 'name' => 'PHP 500 Grocery Voucher', 'description' => 'Voucher usable on the next purchase at any ShopLink branch.', 'points_required' => 450, 'max_per_customer' => 2],
        ];

        foreach ($rewards as $reward) {
            Reward::create(array_merge(['is_active' => true], $reward));
        }
    }
}
