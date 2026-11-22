<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAccountLog;
use App\Models\CustomerNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CustomerManagementHelpers;
use Tests\TestCase;

/**
 * Module 1 (registration) and Module 6 (account update and maintenance).
 */
class CustomerAccountTest extends TestCase
{
    use RefreshDatabase, CustomerManagementHelpers;

    public function test_registration_generates_ids_membership_log_and_notification(): void
    {
        $this->actingAsRole('Customer Service');

        $response = $this->postJson('/api/customers', $this->customerPayload())->assertCreated();

        $id = $response->json('data.id');
        $response->assertJsonPath('data.customer_code', sprintf('CUST-%06d', $id))
            ->assertJsonPath('data.membership_number', sprintf('SLM-%s-%06d', now()->format('Y'), $id))
            ->assertJsonPath('data.status', 'Pending Verification')
            ->assertJsonPath('data.membership.level.name', 'Regular');

        $this->assertDatabaseHas('customer_account_logs', ['customer_id' => $id, 'action' => 'registered']);
        $this->assertDatabaseHas('customer_notifications', ['customer_id' => $id, 'type' => 'registration', 'status' => 'Sent']);
    }

    public function test_registration_validates_required_fields(): void
    {
        $this->actingAsRole('Customer Service');

        $this->postJson('/api/customers', ['full_name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['full_name', 'phone', 'preferred_contact_method', 'customer_type', 'registration_channel']);

        $this->postJson('/api/customers', $this->customerPayload(['phone' => 'abc', 'email' => 'not-an-email']))
            ->assertJsonValidationErrors(['phone', 'email']);
    }

    public function test_duplicate_phone_is_detected_after_normalization(): void
    {
        $this->actingAsRole('Customer Service');
        $this->postJson('/api/customers', $this->customerPayload())->assertCreated();

        $this->postJson('/api/customers', $this->customerPayload(['full_name' => 'Other Person', 'email' => null, 'phone' => '+63 917 123 4567']))
            ->assertStatus(409)
            ->assertJsonPath('data.requires_confirmation', false)
            ->assertJsonPath('data.duplicates.0.reasons.0', 'Same contact number');

        $this->assertSame(1, Customer::count());
    }

    public function test_possible_duplicate_by_name_and_birthday_requires_confirmation(): void
    {
        $this->actingAsRole('Customer Service');
        $this->postJson('/api/customers', $this->customerPayload())->assertCreated();

        $other = $this->customerPayload(['phone' => '09998887777', 'email' => 'maria2@example.com']);

        $this->postJson('/api/customers', $other)
            ->assertStatus(409)
            ->assertJsonPath('data.requires_confirmation', true)
            ->assertJsonPath('data.duplicates.0.reasons.0', 'Same name and birthday');

        $this->postJson('/api/customers', array_merge($other, ['confirm_not_duplicate' => true]))->assertCreated();
    }

    public function test_verification_and_activation_workflow(): void
    {
        $this->actingAsRole('Customer Service');
        $id = $this->postJson('/api/customers', $this->customerPayload())->json('data.id');

        $this->postJson("/api/customers/{$id}/activate")->assertUnprocessable();

        $this->postJson("/api/customers/{$id}/verify", ['verification_method' => 'Government-issued ID'])
            ->assertOk()->assertJsonPath('data.status', 'Verified');

        $this->postJson("/api/customers/{$id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'Active')
            ->assertJsonPath('data.membership.level.name', 'Registered');

        $this->assertEqualsCanonicalizing(
            ['registered', 'verified', 'activated'],
            CustomerAccountLog::where('customer_id', $id)->pluck('action')->all()
        );
        $this->assertTrue(CustomerNotification::where('customer_id', $id)->where('type', 'welcome')->exists());
    }

    public function test_status_changes_require_reason_and_are_logged(): void
    {
        $customer = $this->activeCustomer();
        $this->actingAsRole('Customer Service');

        $this->postJson("/api/customers/{$customer->id}/status", ['status' => 'Suspended'])->assertUnprocessable();
        $this->postJson("/api/customers/{$customer->id}/status", ['status' => 'Suspended', 'reason' => 'Under investigation'])
            ->assertOk()->assertJsonPath('data.status', 'Suspended');

        $this->assertDatabaseHas('customer_account_logs', ['customer_id' => $customer->id, 'action' => 'status_changed']);
    }

    public function test_account_update_requires_identity_check_and_preserves_ids(): void
    {
        $customer = $this->activeCustomer();
        $this->actingAsRole('Customer Service');

        $this->putJson("/api/customers/{$customer->id}", ['phone' => '09170000000'])->assertUnprocessable();

        $this->putJson("/api/customers/{$customer->id}", [
            'phone' => '09170000000',
            'preferred_branch_id' => null,
            'identity_verified' => true,
            'verification_method' => 'Government-issued ID',
            'request_channel' => 'In-store',
            // Attempted changes to protected fields are ignored.
            'customer_code' => 'HACKED',
            'status' => 'VIP',
        ])->assertOk()
            ->assertJsonPath('data.phone', '09170000000')
            ->assertJsonPath('data.customer_code', $customer->customer_code)
            ->assertJsonPath('data.membership_number', $customer->membership_number)
            ->assertJsonPath('data.status', 'Active');

        $log = CustomerAccountLog::where('customer_id', $customer->id)->where('action', 'updated')->firstOrFail();
        $this->assertSame('09171234567', $log->changes['phone']['old']);
        $this->assertTrue($log->identity_verified);
    }

    public function test_preference_change_does_not_require_identity_check(): void
    {
        $customer = $this->activeCustomer();
        $this->actingAsRole('Customer Service');

        $this->putJson("/api/customers/{$customer->id}", ['preferred_contact_method' => 'SMS'])
            ->assertOk()->assertJsonPath('data.preferred_contact_method', 'SMS');
    }

    public function test_customer_with_history_cannot_be_deleted(): void
    {
        $customer = $this->activeCustomer();
        $this->setPoints($customer, 10);

        $this->actingAsRole('Manager');
        $this->deleteJson("/api/customers/{$customer->id}")->assertStatus(400);

        $fresh = $this->activeCustomer(['phone' => '09001112222', 'email' => 'fresh@example.com', 'full_name' => 'Fresh One'], false);
        $this->deleteJson("/api/customers/{$fresh->id}")->assertOk();
    }

    public function test_listing_supports_search_filters_and_inactivity(): void
    {
        $this->activeCustomer();
        $this->activeCustomer(['phone' => '09001112222', 'email' => 'juan@example.com', 'full_name' => 'Juan Dela Cruz'], false);

        $this->actingAsRole('Marketing');

        $this->getJson('/api/customers?search=Juan')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/customers?loyalty=enrolled')->assertJsonPath('data.total', 1);
        $this->getJson('/api/customers?status=Active&inactive_days=30')->assertJsonPath('data.total', 2);
        $this->getJson('/api/customers?status=Pending Verification')->assertJsonPath('data.total', 0);
    }
}
