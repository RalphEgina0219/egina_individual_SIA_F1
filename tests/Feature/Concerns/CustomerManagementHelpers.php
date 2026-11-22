<?php

namespace Tests\Feature\Concerns;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CustomerAccountService;
use App\Services\LoyaltyService;

trait CustomerManagementHelpers
{
    protected function userWithRole(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => $role], $attributes));
    }

    protected function actingAsRole(string $role): User
    {
        $user = $this->userWithRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    protected function customerPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Maria Santos',
            'phone' => '09171234567',
            'email' => 'maria@example.com',
            'birthday' => '1990-03-14',
            'address' => 'Calapan City',
            'preferred_contact_method' => 'Email',
            'customer_type' => 'Individual',
            'registration_channel' => 'In-store',
        ], $overrides);
    }

    /**
     * Registered, verified, active and (optionally) loyalty-enrolled customer.
     */
    protected function activeCustomer(array $overrides = [], bool $enroll = true): Customer
    {
        $staff = $this->userWithRole('Manager');

        return app(CustomerAccountService::class)->register($this->customerPayload($overrides), $staff, [
            'identity_verified' => true,
            'verification_method' => 'Government-issued ID',
            'activate' => true,
            'enroll_loyalty' => $enroll,
        ]);
    }

    protected function configureLoyalty(float $amountPerPoint = 100, array $extra = []): void
    {
        app(LoyaltyService::class)->updateSettings(array_merge([
            'program_enabled' => true,
            'amount_per_point' => $amountPerPoint,
            'minimum_purchase_amount' => 0,
            'auto_upgrade_enabled' => true,
        ], $extra), $this->userWithRole('Manager'));
    }

    protected function stockedProduct(float $price = 250, int $quantity = 100): Product
    {
        $product = Product::create([
            'product_name' => 'Rice 5kg ' . uniqid(),
            'unit_price' => $price,
            'sku' => 'SKU-' . uniqid(),
        ]);
        $warehouse = Warehouse::firstOrCreate(['warehouse_name' => 'Main'], ['location' => 'Calapan']);
        Inventory::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => $quantity]);

        return $product;
    }

    protected function setPoints(Customer $customer, int $points): void
    {
        app(LoyaltyService::class)->post($customer, $points, 'adjustment', 'Test balance');
    }
}
