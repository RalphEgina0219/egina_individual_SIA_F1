<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B7: talaan ng bawat tawag papasok (in) at palabas (out) sa ibang system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 3);              // in / out
            $table->string('system', 10);                // CRM / PROC / INV / HR
            $table->string('method', 10);
            $table->string('url', 500);
            $table->string('reference_number', 100)->nullable();
            $table->json('request_body')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->index('created_at');
            $table->index(['system', 'direction']);
            $table->index('reference_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
    }
};
