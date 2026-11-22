<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('code', 30)->unique();
            $table->unsignedTinyInteger('rank')->unique();
            // Lifetime points needed for automatic upgrade; NULL = manual assignment only.
            $table->unsignedInteger('min_lifetime_points')->nullable();
            $table->decimal('points_multiplier', 5, 2)->default(1.00);
            $table->string('description', 255)->nullable();
            $table->text('benefits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Reference data: the five membership levels in the AS-IS documentation.
        // Upgrade thresholds are left empty for administrators to configure.
        $now = now();
        DB::table('membership_levels')->insert([
            ['name' => 'Regular', 'code' => 'REGULAR', 'rank' => 1, 'description' => 'Customer record created; account not yet verified.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Registered', 'code' => 'REGISTERED', 'rank' => 2, 'description' => 'Verified and activated customer account.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Loyalty Member', 'code' => 'LOYALTY', 'rank' => 3, 'description' => 'Enrolled in the ShopLink loyalty program.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Gold Member', 'code' => 'GOLD', 'rank' => 4, 'description' => 'Upgraded loyalty member.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'VIP', 'code' => 'VIP', 'rank' => 5, 'description' => 'Highest membership level.', 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('customer_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('membership_level_id')->constrained('membership_levels')->restrictOnDelete();
            $table->timestamp('loyalty_enrolled_at')->nullable();
            $table->foreignId('enrolled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('points_balance')->default(0);
            $table->unsignedInteger('lifetime_points_earned')->default(0);
            $table->unsignedInteger('lifetime_points_redeemed')->default(0);
            $table->timestamp('last_points_activity_at')->nullable();
            $table->timestamp('level_assigned_at')->nullable();
            // When true, automatic upgrades do not change a manually assigned level.
            $table->boolean('level_locked')->default(false);
            $table->timestamps();

            $table->index('membership_level_id');
        });

        $registeredId = DB::table('membership_levels')->where('code', 'REGISTERED')->value('id');
        DB::table('customers')->orderBy('id')->pluck('id')->each(function ($customerId) use ($registeredId, $now) {
            DB::table('customer_memberships')->insert([
                'customer_id' => $customerId,
                'membership_level_id' => $registeredId,
                'level_assigned_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_memberships');
        Schema::dropIfExists('membership_levels');
    }
};
