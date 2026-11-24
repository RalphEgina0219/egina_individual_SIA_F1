<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B7: mga request na hinihintay maipadala sa ibang system.
 * Dito muna inilalagay; ang integration:process-outbox ang nagpapadala.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('target_system', 10);         // PROC / INV / HR
            $table->string('method', 10);                // POST, PUT...
            $table->string('endpoint', 255);             // hal. /stock-movements
            $table->json('payload');
            $table->string('reference_number', 100);
            $table->string('status', 10)->default('pending'); // pending / sent / failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // Walang doble: isang row lang bawat (system, endpoint, reference).
            $table->unique(['target_system', 'endpoint', 'reference_number'], 'integration_outbox_target_endpoint_ref_unique');
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_outbox');
    }
};
