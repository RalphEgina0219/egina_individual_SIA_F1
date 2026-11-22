<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Walk-in sales may be recorded without a customer account.
            $table->foreignId('customer_id')->nullable()->change();
            $table->string('transaction_number', 30)->nullable()->unique()->after('id');
            $table->foreignId('branch_id')->nullable()->after('user_id')
                ->constrained('branches')->nullOnDelete();
            $table->index('order_date');
        });

        DB::table('orders')->orderBy('id')->get(['id', 'order_date'])->each(function ($order) {
            DB::table('orders')->where('id', $order->id)->update([
                'transaction_number' => sprintf('TXN-%s-%06d', date('Ymd', strtotime($order->order_date)), $order->id),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropIndex(['order_date']);
            $table->dropUnique(['transaction_number']);
            $table->dropColumn(['transaction_number', 'branch_id']);
        });
    }
};
