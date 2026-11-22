<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehousesSeeder extends Seeder
{
    public function run(): void
    {
        $warehouses = [
            [
                'warehouse_name' => 'Main Warehouse',
                'location' => 'Calapan City'
            ],
            [
                'warehouse_name' => 'Branch 2 Stockroom',
                'location' => 'Calapan City'
            ]
        ];

        foreach ($warehouses as $warehouse) {
            Warehouse::create($warehouse);
        }
    }
}
