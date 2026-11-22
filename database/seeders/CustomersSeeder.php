<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use App\Services\CustomerAccountService;
use Illuminate\Database\Seeder;

/**
 * Sample customer accounts registered through the same service the API
 * uses, so each one receives a Customer ID, Membership Number, membership
 * record and account log. No purchases, points or feedback are seeded.
 */
class CustomersSeeder extends Seeder
{
    public function run(CustomerAccountService $accounts): void
    {
        $staff = User::where('role', 'Manager')->firstOrFail();
        $mainBranch = Branch::where('code', 'CAL-MAIN')->value('id');

        $customers = [
            [['full_name' => 'Maria Santos', 'phone' => '09171234567', 'email' => 'maria.santos@example.com', 'birthday' => '1990-03-14'], ['identity_verified' => true, 'activate' => true, 'enroll_loyalty' => true]],
            [['full_name' => 'Juan Dela Cruz', 'phone' => '09181234567', 'email' => 'juan.delacruz@example.com', 'birthday' => '1985-07-02'], ['identity_verified' => true, 'activate' => true]],
            [['full_name' => 'Ana Reyes', 'phone' => '09191234567', 'email' => null, 'birthday' => '1958-11-20', 'customer_type' => 'Senior Citizen'], ['identity_verified' => true]],
            [['full_name' => 'Carlo Mendoza', 'phone' => '09201234567', 'email' => 'carlo.mendoza@example.com', 'birthday' => null], []],
        ];

        foreach ($customers as [$data, $options]) {
            $accounts->register(array_merge([
                'address' => 'Calapan City, Oriental Mindoro',
                'preferred_branch_id' => $mainBranch,
                'preferred_contact_method' => $data['email'] ?? null ? 'Email' : 'SMS',
                'customer_type' => 'Individual',
                'registration_channel' => 'In-store',
            ], $data), $staff, array_merge(['verification_method' => 'Government-issued ID'], $options));
        }
    }
}
