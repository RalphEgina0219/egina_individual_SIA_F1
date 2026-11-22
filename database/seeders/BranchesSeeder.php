<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

/**
 * Sample branch records. Replace with the actual ShopLink branch list in
 * Settings or through the /api/branches endpoint.
 */
class BranchesSeeder extends Seeder
{
    public function run(): void
    {
        $branches = [
            ['code' => 'CAL-MAIN', 'name' => 'ShopLink Calapan Main', 'address' => 'Calapan City', 'city' => 'Calapan City'],
            ['code' => 'CAL-02', 'name' => 'ShopLink Calapan Branch 2', 'address' => 'Calapan City', 'city' => 'Calapan City'],
        ];

        foreach ($branches as $branch) {
            Branch::create($branch);
        }
    }
}
