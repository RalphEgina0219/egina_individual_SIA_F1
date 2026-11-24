<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 / F3: sino ang cashier (employee_no) at ang status ng pagpapadala ng
 * benta sa Inventory (not_sent / pending / synced / failed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('cashier_employee_no', 20)->nullable()->after('user_id');
            $table->string('stock_sync_status', 10)->default('not_sent')->after('order_status');
            $table->index('cashier_employee_no');
            $table->index('stock_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['cashier_employee_no']);
            $table->dropIndex(['stock_sync_status']);
            $table->dropColumn(['cashier_employee_no', 'stock_sync_status']);
        });
    }
};
