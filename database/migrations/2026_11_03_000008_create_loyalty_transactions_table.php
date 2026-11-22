<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // earn, redeem, adjustment, reversal, refund, expiry
            $table->string('type', 20);
            // Signed: positive adds to the balance, negative deducts.
            $table->integer('points');
            $table->integer('balance_after');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reward_redemption_id')->nullable()->constrained()->nullOnDelete();
            // Unique business key (e.g. "order:15:earn") preventing duplicate postings.
            $table->string('reference', 100)->nullable()->unique();
            $table->string('description', 255);
            $table->text('reason')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('type');
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
