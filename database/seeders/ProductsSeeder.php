<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductsSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['product_name' => 'Rice 5kg', 'category' => 'Food & Beverages', 'unit_price' => 285.00, 'sku' => 'GRC-RICE5-001', 'description' => 'Well-milled rice, 5 kg bag'],
            ['product_name' => 'Fresh Milk 1L', 'category' => 'Food & Beverages', 'unit_price' => 98.50, 'sku' => 'DRY-MILK1L-001', 'description' => 'Full cream fresh milk, 1 liter'],
            ['product_name' => 'Canned Sardines 155g', 'category' => 'Food & Beverages', 'unit_price' => 24.75, 'sku' => 'CAN-SARD155-001', 'description' => 'Sardines in tomato sauce'],
            ['product_name' => 'Cooking Oil 1L', 'category' => 'Food & Beverages', 'unit_price' => 115.00, 'sku' => 'GRC-OIL1L-001', 'description' => 'Vegetable cooking oil, 1 liter'],
            ['product_name' => 'Laundry Detergent 1kg', 'category' => 'Other', 'unit_price' => 165.00, 'sku' => 'HHD-DET1KG-001', 'description' => 'Powder laundry detergent'],
            ['product_name' => 'Bath Soap 135g', 'category' => 'Beauty', 'unit_price' => 52.00, 'sku' => 'PRC-SOAP135-001', 'description' => 'Moisturizing bath soap'],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}
