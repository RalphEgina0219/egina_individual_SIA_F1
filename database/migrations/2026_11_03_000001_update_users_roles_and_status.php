<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen the role column (was enum Sales/Warehouse/Manager) so the
     * Admin, Customer Service and Marketing roles can be stored, and add an
     * active flag used by user management.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 30)->default('Sales')->change();
            $table->boolean('is_active')->default(true)->after('phone');
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('is_active');
        });
    }
};
