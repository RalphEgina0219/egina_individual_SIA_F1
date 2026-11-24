<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - employees: kopya (read-only) ng employees ng HR. Ang employee_no ang unique.
 *   Dito kinukuha ang listahan ng "Staff involved" at dito lumalabas si EMP-0010 (B10).
 * - integration_sync_cursors: ang huling updated_at na nakuha sa bawat listahan,
 *   para sa ?updated_since= ng integration:sync-masterdata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_no', 20)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email')->nullable();
            $table->string('branch_code', 20)->nullable();
            $table->string('department_code', 20)->nullable();
            $table->string('position', 100)->nullable();
            $table->string('employment_status', 20)->default('Active');
            $table->boolean('can_login')->default(true);
            $table->string('crm_role', 30)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('resource', 50)->unique();   // hal. HR./employees
            $table->string('last_updated_at', 40)->nullable(); // ISO galing sa may-ari
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_cursors');
        Schema::dropIfExists('employees');
    }
};
