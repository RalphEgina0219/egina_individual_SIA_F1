<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_feedback', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 30)->nullable()->unique();
            // Nullable so walk-in or anonymous concerns can still be recorded.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contact_name', 100)->nullable();
            $table->string('contact_info', 100)->nullable();
            $table->string('type', 30);
            $table->string('category', 50)->nullable();
            $table->string('channel', 20);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('subject', 150);
            $table->text('message');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('priority', 10)->default('Medium');
            $table->string('status', 20)->default('Pending');
            $table->string('department', 50)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->text('resolution_summary')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('customer_notified_at')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('status');
            $table->index('received_at');
        });

        Schema::create('complaint_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_feedback_id')->constrained('customer_feedback')->cascadeOnDelete();
            $table->string('department', 50);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamp('assigned_at');
            $table->timestamps();
        });

        Schema::create('feedback_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_feedback_id')->constrained('customer_feedback')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Internal Note, Investigation, Resolution, Customer Communication, Status Update
            $table->string('response_type', 30);
            $table->text('message');
            $table->string('status_from', 20)->nullable();
            $table->string('status_to', 20)->nullable();
            $table->string('communicated_via', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_responses');
        Schema::dropIfExists('complaint_assignments');
        Schema::dropIfExists('customer_feedback');
    }
};
