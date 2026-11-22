<?php

namespace Tests\Feature;

use App\Models\LoyaltyTransaction;
use App\Models\MembershipLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CustomerManagementHelpers;
use Tests\TestCase;

/**
 * Module 2 (loyalty points) and Module 3 (purchase association).
 */
class LoyaltyPointsTest extends TestCase
{
    use RefreshDatabase, CustomerManagementHelpers;

    private function sell(array $payload, int $quantity = 2, float $price = 250): int
    {
        $product = $this->stockedProduct($price);

        return $this->postJson('/api/orders', array_merge([
            'payment_method' => 'Cash',
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ], $payload))->assertCreated()->json('data.id');
    }

    public function test_no_points_are_earned_until_the_program_is_configured(): void
    {
        $customer = $this->activeCustomer();
        $this->actingAsRole('Sales');

        $orderId = $this->sell(['customer_id' => $customer->id]);
        $this->putJson("/api/orders/{$orderId}", ['order_status' => 'Delivered'])
            ->assertOk()
            ->assertJsonPath('loyalty.awarded', false);

        $this->assertSame(0, LoyaltyTransaction::count());
    }

    public function test_enabling_the_program_requires_a_rate(): void
    {
        $this->actingAsRole('Manager');

        $this->putJson('/api/loyalty/settings', [
            'program_enabled' => true, 'amount_per_point' => null,
            'minimum_purchase_amount' => 0, 'auto_upgrade_enabled' => true,
        ])->assertUnprocessable();

        $this->putJson('/api/loyalty/settings', [
            'program_enabled' => true, 'amount_per_point' => 200,
            'minimum_purchase_amount' => 0, 'auto_upgrade_enabled' => true,
        ])->assertOk()->assertJsonPath('data.amount_per_point', 200);
    }

    public function test_completed_purchase_earns_points_once_and_cancellation_reverses(): void
    {
        $this->configureLoyalty(100);
        $customer = $this->activeCustomer();
        $this->actingAsRole('Sales');

        // 2 x 250 = 500 => 5 points at 100 per point
        $orderId = $this->sell(['customer_code' => $customer->customer_code]);
        $this->putJson("/api/orders/{$orderId}", ['order_status' => 'Delivered'])
            ->assertOk()
            ->assertJsonPath('loyalty.awarded', true)
            ->assertJsonPath('loyalty.points', 5);

        $this->assertSame(5, $customer->membership()->first()->points_balance);

        // Re-applying the status and syncing must not double-post.
        $this->putJson("/api/orders/{$orderId}", ['order_status' => 'Delivered'])->assertJsonPath('loyalty.awarded', false);
        $this->actingAsRole('Customer Service');
        $this->postJson("/api/loyalty/orders/{$orderId}/sync")->assertUnprocessable();
        $this->assertSame(1, LoyaltyTransaction::where('type', 'earn')->count());

        $this->actingAsRole('Sales');
        $this->putJson("/api/orders/{$orderId}", ['order_status' => 'Cancelled'])->assertOk();
        $this->assertSame(0, $customer->membership()->first()->points_balance);
        $this->assertDatabaseHas('loyalty_transactions', ['order_id' => $orderId, 'type' => 'reversal', 'points' => -5]);

        $this->putJson("/api/orders/{$orderId}", ['order_status' => 'Delivered'])->assertUnprocessable();
    }

    public function test_minimum_purchase_and_level_multiplier_are_applied(): void
    {
        $this->configureLoyalty(100, ['minimum_purchase_amount' => 600]);
        MembershipLevel::where('code', 'LOYALTY')->update(['points_multiplier' => 2]);
        $customer = $this->activeCustomer();
        $this->actingAsRole('Sales');

        $small = $this->sell(['customer_id' => $customer->id], 2);   // 500 < 600 minimum
        $this->putJson("/api/orders/{$small}", ['order_status' => 'Delivered'])->assertJsonPath('loyalty.awarded', false);

        $big = $this->sell(['customer_id' => $customer->id], 4);     // 1000 => 10 x 2
        $this->putJson("/api/orders/{$big}", ['order_status' => 'Delivered'])->assertJsonPath('loyalty.points', 20);
    }

    public function test_non_members_and_walk_ins_do_not_earn(): void
    {
        $this->configureLoyalty(100);
        $customer = $this->activeCustomer([], false);
        $this->actingAsRole('Sales');

        $memberless = $this->sell(['customer_id' => $customer->id]);
        $this->putJson("/api/orders/{$memberless}", ['order_status' => 'Delivered'])
            ->assertJsonPath('loyalty.reason', 'Customer is not enrolled in the loyalty program.');

        $walkIn = $this->sell([]);
        $this->putJson("/api/orders/{$walkIn}", ['order_status' => 'Delivered'])
            ->assertJsonPath('loyalty.reason', 'Order is not associated with a customer.');
    }

    public function test_walk_in_purchase_can_be_associated_and_earns_points(): void
    {
        $this->configureLoyalty(100);
        $customer = $this->activeCustomer();
        $this->actingAsRole('Sales');
        $orderId = $this->sell([]);
        $this->putJson("/api/orders/{$orderId}", ['order_status' => 'Delivered']);

        $this->actingAsRole('Customer Service');
        $this->postJson("/api/purchase-history/{$orderId}/associate-customer", ['customer_id' => $customer->id, 'reason' => 'Forgot membership card'])
            ->assertOk()
            ->assertJsonPath('data.points.awarded', true);

        $this->postJson("/api/purchase-history/{$orderId}/associate-customer", ['customer_id' => $customer->id, 'reason' => 'Again'])
            ->assertUnprocessable();

        $this->getJson("/api/customers/{$customer->id}/purchases")
            ->assertOk()
            ->assertJsonPath('data.summary.total_transactions', 1)
            ->assertJsonPath('data.orders.data.0.points_earned', 5);

        $this->getJson("/api/purchase-history/{$orderId}/verify")->assertOk()->assertJsonPath('data.all_passed', true);
    }

    public function test_blocked_customers_cannot_be_used_in_transactions(): void
    {
        $customer = $this->activeCustomer();
        $customer->update(['status' => 'Blocked']);
        $product = $this->stockedProduct();
        $this->actingAsRole('Sales');

        $this->postJson('/api/orders', [
            'customer_id' => $customer->id, 'payment_method' => 'Cash',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable();
    }

    public function test_manual_adjustment_requires_reason_and_cannot_go_negative(): void
    {
        $customer = $this->activeCustomer();
        $this->actingAsRole('Manager');

        $this->postJson("/api/customers/{$customer->id}/loyalty/adjust", ['points' => 50])->assertJsonValidationErrors('reason');
        $this->postJson("/api/customers/{$customer->id}/loyalty/adjust", ['points' => 50, 'reason' => 'Service recovery credit'])
            ->assertCreated()->assertJsonPath('data.balance_after', 50);
        $this->postJson("/api/customers/{$customer->id}/loyalty/adjust", ['points' => -80, 'reason' => 'Correction of error'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('loyalty_transactions', ['customer_id' => $customer->id, 'type' => 'adjustment', 'reason' => 'Service recovery credit']);
        $this->assertDatabaseHas('customer_account_logs', ['customer_id' => $customer->id, 'action' => 'points_adjusted']);
    }

    public function test_automatic_membership_upgrade_uses_configured_threshold(): void
    {
        $this->configureLoyalty(100);
        $customer = $this->activeCustomer();
        $this->actingAsRole('Manager');

        $gold = MembershipLevel::where('code', 'GOLD')->first();
        $this->putJson("/api/loyalty/levels/{$gold->id}", [
            'min_lifetime_points' => 10, 'points_multiplier' => 1, 'is_active' => true,
        ])->assertOk();

        $this->actingAsRole('Sales');
        $orderId = $this->sell(['customer_id' => $customer->id], 4); // 1000 => 10 points
        $this->putJson("/api/orders/{$orderId}", ['order_status' => 'Delivered']);

        $this->assertSame('GOLD', $customer->membership()->first()->level->code);
        $this->assertDatabaseHas('customer_notifications', ['customer_id' => $customer->id, 'type' => 'membership_upgrade']);
    }

    public function test_enrollment_requires_eligible_account(): void
    {
        $this->actingAsRole('Customer Service');
        $id = $this->postJson('/api/customers', $this->customerPayload())->json('data.id');

        $this->postJson("/api/customers/{$id}/loyalty/enroll")->assertUnprocessable();

        $this->postJson("/api/customers/{$id}/verify", ['verification_method' => 'Government-issued ID']);
        $this->postJson("/api/customers/{$id}/loyalty/enroll")->assertCreated()->assertJsonPath('data.level.name', 'Loyalty Member');
        $this->postJson("/api/customers/{$id}/loyalty/enroll")->assertUnprocessable();
    }
}
