<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->text('value')->nullable();
            $table->string('description', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Earning stays disabled with no conversion rate until an authorized
        // administrator configures the program in Loyalty Membership > Settings.
        $now = now();
        DB::table('loyalty_settings')->insert([
            ['key' => 'program_enabled', 'value' => '0', 'description' => 'Whether completed purchases earn loyalty points.', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'amount_per_point', 'value' => null, 'description' => 'Purchase amount (PHP) required to earn 1 point.', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'minimum_purchase_amount', 'value' => '0', 'description' => 'Minimum purchase total (PHP) eligible for points.', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'points_expiry_months', 'value' => null, 'description' => 'Months without points activity before the balance expires. Empty = never.', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'auto_upgrade_enabled', 'value' => '1', 'description' => 'Automatically upgrade membership levels based on lifetime points.', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_settings');
    }
};
