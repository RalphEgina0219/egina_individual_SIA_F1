<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3: link sa HR (employee_no) at ang login status galing HR (F1).
 * Lahat ay nullable o may default, para walang masira sa live data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_no', 20)->nullable()->unique()->after('id');
            $table->string('employment_status', 20)->nullable()->after('is_active');
            $table->boolean('can_login')->default(true)->after('employment_status');
            $table->string('branch_code', 20)->nullable()->after('can_login');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_no']);
            $table->dropColumn(['employee_no', 'employment_status', 'can_login', 'branch_code']);
        });
    }
};
