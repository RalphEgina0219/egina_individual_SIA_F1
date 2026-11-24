<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 / A6: products.sku ay mayroon na (unique). Idinadagdag:
 * - legacy_sku: itinatago ang lumang SKU bago palitan ng SKU-xxxx
 * - status: galing INV (Active / Inactive)
 * - inv_stock: ang stock sa INV (pang-display lang, hindi pumapalit sa lokal na stock)
 * warehouses: warehouse_code (WH-001) at branch_code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('legacy_sku', 50)->nullable()->after('sku');
            $table->string('status', 10)->default('Active')->after('legacy_sku');
            $table->integer('inv_stock')->nullable()->after('status');
            $table->timestamp('inv_stock_synced_at')->nullable()->after('inv_stock');
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('warehouse_code', 20)->nullable()->unique()->after('id');
            $table->string('branch_code', 20)->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['legacy_sku', 'status', 'inv_stock', 'inv_stock_synced_at']);
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropUnique(['warehouse_code']);
            $table->dropColumn(['warehouse_code', 'branch_code']);
        });
    }
};
