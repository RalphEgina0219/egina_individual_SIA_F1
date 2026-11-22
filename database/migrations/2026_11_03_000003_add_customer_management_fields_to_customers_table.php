<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_code', 20)->nullable()->unique()->after('id');
            $table->string('membership_number', 20)->nullable()->unique()->after('customer_code');
            $table->date('birthday')->nullable()->after('address');
            $table->foreignId('preferred_branch_id')->nullable()->after('birthday')
                ->constrained('branches')->nullOnDelete();
            $table->string('preferred_contact_method', 20)->default('SMS')->after('preferred_branch_id');
            $table->string('customer_type', 30)->default('Individual')->after('preferred_contact_method');
            $table->string('status', 30)->default('Pending Verification')->after('customer_type');
            $table->string('status_reason', 255)->nullable()->after('status');
            $table->timestamp('status_changed_at')->nullable()->after('status_reason');
            $table->string('registration_channel', 20)->default('In-store')->after('registration_date');
            $table->foreignId('registered_by')->nullable()->after('registration_channel')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('verification_method', 50)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->text('notes')->nullable();

            $table->index('status');
            $table->index('full_name');
            $table->index('birthday');
        });

        // Existing customers were already transacting, so they are treated
        // as active accounts and receive generated identifiers.
        DB::table('customers')->orderBy('id')->get()->each(function ($customer) {
            DB::table('customers')->where('id', $customer->id)->update([
                'customer_code' => sprintf('CUST-%06d', $customer->id),
                'membership_number' => sprintf('SLM-%s-%06d', date('Y', strtotime($customer->created_at ?? 'now')), $customer->id),
                'status' => 'Active',
                'activated_at' => $customer->created_at ?? now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['preferred_branch_id']);
            $table->dropForeign(['registered_by']);
            $table->dropForeign(['verified_by']);
            $table->dropIndex(['status']);
            $table->dropIndex(['full_name']);
            $table->dropIndex(['birthday']);
            $table->dropUnique(['customer_code']);
            $table->dropUnique(['membership_number']);
            $table->dropColumn([
                'customer_code', 'membership_number', 'birthday', 'preferred_branch_id',
                'preferred_contact_method', 'customer_type', 'status', 'status_reason',
                'status_changed_at', 'registration_channel', 'registered_by', 'verified_at',
                'verified_by', 'verification_method', 'activated_at', 'notes',
            ]);
        });
    }
};
