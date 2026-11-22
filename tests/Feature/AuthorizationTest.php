<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CustomerManagementHelpers;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase, CustomerManagementHelpers;

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/customers')->assertUnauthorized();
        $this->getJson('/api/customer-management/dashboard')->assertUnauthorized();
    }

    public function test_public_registration_is_closed(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Intruder', 'email' => 'x@example.com', 'password' => 'password123',
            'password_confirmation' => 'password123', 'role' => 'Manager',
        ])->assertUnauthorized();
    }

    public function test_login_returns_permissions_and_rejects_inactive_users(): void
    {
        User::factory()->create(['email' => 'cs@example.com', 'password' => 'password123', 'role' => 'Customer Service']);
        User::factory()->create(['email' => 'off@example.com', 'password' => 'password123', 'role' => 'Manager', 'is_active' => false]);

        $this->postJson('/api/login', ['email' => 'cs@example.com', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('user.role', 'Customer Service')
            ->assertJsonFragment(['customers.create'])
            ->assertJsonMissing(['loyalty.adjust']);

        $this->postJson('/api/login', ['email' => 'off@example.com', 'password' => 'password123'])
            ->assertStatus(422);
    }

    public function test_role_matrix_for_customer_management(): void
    {
        $customer = $this->activeCustomer();

        $this->actingAsRole('Warehouse');
        $this->getJson('/api/customers')->assertForbidden();
        $this->getJson('/api/customers/lookup?q=Maria')->assertForbidden();

        $this->actingAsRole('Sales');
        $this->getJson('/api/customers/lookup?q=Maria')->assertOk()->assertJsonPath('data.0.customer_code', $customer->customer_code);
        $this->getJson('/api/customers')->assertForbidden();
        $this->postJson('/api/customers', $this->customerPayload(['phone' => '09990000000']))->assertForbidden();

        $this->actingAsRole('Marketing');
        $this->getJson('/api/customer-management/reports')->assertOk();
        $this->postJson('/api/customers', $this->customerPayload(['phone' => '09990000000']))->assertForbidden();

        $this->actingAsRole('Customer Service');
        $this->getJson('/api/customer-management/reports')->assertForbidden();
        $this->postJson("/api/customers/{$customer->id}/loyalty/adjust", ['points' => 10, 'reason' => 'Goodwill credit'])->assertForbidden();
        $this->putJson('/api/loyalty/settings', ['program_enabled' => false, 'minimum_purchase_amount' => 0, 'auto_upgrade_enabled' => true])->assertForbidden();
    }

    public function test_sensitive_fields_are_hidden_from_marketing(): void
    {
        $customer = $this->activeCustomer();

        $this->actingAsRole('Marketing');
        $this->getJson("/api/customers/{$customer->id}")->assertOk()->assertJsonMissingPath('data.birthday');

        $this->actingAsRole('Customer Service');
        $this->getJson("/api/customers/{$customer->id}")->assertOk()->assertJsonPath('data.birthday', '1990-03-14');
    }

    public function test_existing_modules_are_role_protected(): void
    {
        $product = $this->stockedProduct();

        $this->actingAsRole('Warehouse');
        $this->getJson('/api/inventory')->assertOk();
        $this->getJson('/api/products')->assertOk();
        $this->postJson('/api/orders', ['payment_method' => 'Cash', 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertForbidden();

        $this->actingAsRole('Sales');
        $this->postJson('/api/orders', ['payment_method' => 'Cash', 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $this->postJson('/api/inventory/transfer', [])->assertForbidden();

        $this->actingAsRole('Customer Service');
        $this->getJson('/api/orders')->assertForbidden();
    }

    public function test_user_management_replaces_mock_users(): void
    {
        $manager = $this->actingAsRole('Manager');

        $this->postJson('/api/users', [
            'name' => 'New CS', 'email' => 'newcs@example.com', 'password' => 'password123',
            'password_confirmation' => 'password123', 'role' => 'Customer Service',
        ])->assertCreated();

        $this->getJson('/api/users')->assertOk()->assertJsonFragment(['email' => 'newcs@example.com']);
        $this->putJson("/api/users/{$manager->id}", ['is_active' => false])->assertStatus(422);
    }
}
