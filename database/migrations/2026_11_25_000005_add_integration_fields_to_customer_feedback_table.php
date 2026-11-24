<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A3 / F7 / F8: feedback_number sa format ng B4 (FB-YYYYMMDD-0001),
 * ang "Staff involved" (employee_no) at ang pag-forward sa Procurement.
 * Ang lumang reference_number ay hindi ginagalaw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_feedback', function (Blueprint $table) {
            $table->string('feedback_number', 30)->nullable()->unique()->after('reference_number');
            $table->string('sku', 50)->nullable()->after('product_id');
            $table->string('employee_no', 20)->nullable()->after('sku');
            $table->string('supplier_code', 20)->nullable()->after('employee_no');
            $table->string('forward_to', 10)->nullable()->after('supplier_code');
            $table->string('forward_status', 15)->default('not_forwarded')->after('forward_to');
            $table->timestamp('forwarded_at')->nullable()->after('forward_status');
            $table->string('supplier_issue_number', 30)->nullable()->after('forwarded_at');
            $table->index('employee_no');
            $table->index('forward_status');
        });

        // Bigyan ng feedback_number ang mga dati nang feedback.
        DB::table('customer_feedback')->whereNull('feedback_number')->orderBy('id')
            ->get(['id', 'created_at'])
            ->each(function ($row) {
                $date = Carbon::parse($row->created_at ?? now(), 'UTC')->setTimezone('Asia/Manila')->format('Ymd');
                DB::table('customer_feedback')->where('id', $row->id)
                    ->update(['feedback_number' => sprintf('FB-%s-%04d', $date, $row->id)]);
            });
    }

    public function down(): void
    {
        Schema::table('customer_feedback', function (Blueprint $table) {
            $table->dropUnique(['feedback_number']);
            $table->dropIndex(['employee_no']);
            $table->dropIndex(['forward_status']);
            $table->dropColumn([
                'feedback_number', 'sku', 'employee_no', 'supplier_code',
                'forward_to', 'forward_status', 'forwarded_at', 'supplier_issue_number',
            ]);
        });
    }
};
