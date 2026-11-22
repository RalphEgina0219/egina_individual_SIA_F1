<?php

namespace Tests\Feature;

use App\Models\LoyaltyTransaction;
use App\Models\MembershipLevel;
use App\Models\Reward;
use App\Models\RewardRedemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CustomerManagementHelpers;
use Tests\TestCase;

/**
 * Module 5: loyalty reward redemption.
 */
class RewardRedemptionTest extends TestCase
{
    use RefreshDatabase, CustomerManagementHelpers;

    private function reward(array $attributes = []): Reward
    {
        return Reward::create(array_merge([
            'code' => 'GV-' . uniqid(), 'name' => 'Grocery Voucher', 'points_required' => 100, 'is_active' => true,
        ], $attributes));
    }

    private function redeem(int $customerId, int $rewardId, string $key, int $quantity = 1)
    {
        return $this->postJson('/api/redemptions', [
            'customer_id' => $customerId, 'reward_id' => $rewardId, 'quantity' => $quantity, 'idempotency_key' => "test-key-{$key}",
        ]);
    }

    public function test_redemption_deducts_points_once_and_records_transaction(): void
    {
        $customer = $this->activeCustomer();
        $this->setPoints($customer, 250);
        $reward = $this->reward(['stock_quantity' => 5]);
        $this->actingAsRole('Customer Service');

        $first = $this->redeem($customer->id, $reward->id, 'key-abc-123')
            ->assertCreated()
            ->assertJsonPath('data.points_used', 100)
            ->assertJsonPath('data.points_balance', 150)
            ->assertJsonPath('data.duplicate_request', false);

        // Double submission returns the original redemption without deducting again.
        $this->redeem($customer->id, $reward->id, 'key-abc-123')
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.duplicate_request', true);

        $this->assertSame(150, $customer->membership()->first()->points_balance);
        $this->assertSame(4, $reward->fresh()->stock_quantity);
        $this->assertSame(1, LoyaltyTransaction::where('type', 'redeem')->count());
        $this->assertStringStartsWith('RDM-', RewardRedemption::first()->redemption_number);
        $this->assertDatabaseHas('customer_notifications', ['customer_id' => $customer->id, 'type' => 'points_redeemed']);
    }

    public function test_eligibility_rules_are_enforced_on_the_server(): void
    {
        $customer = $this->activeCustomer();
        $this->setPoints($customer, 150);
        $this->actingAsRole('Customer Service');

        $this->redeem($customer->id, $this->reward(['points_required' => 200])->id, 'k-insufficient')
            ->assertUnprocessable()->assertJsonFragment(['message' => 'Insufficient points: requires 200, available 150.']);

        $this->redeem($customer->id, $this->reward(['stock_quantity' => 0])->id, 'k-stock')
            ->assertUnprocessable()->assertJsonFragment(['message' => 'Reward is not currently available.']);
        $this->redeem($customer->id, $this->reward(['is_active' => false])->id, 'k-inactive')
            ->assertUnprocessable()->assertJsonFragment(['message' => 'Reward is not currently available.']);
        $this->redeem($customer->id, $this->reward(['valid_until' => now()->subDay()->toDateString()])->id, 'k-expired')
            ->assertUnprocessable()->assertJsonFragment(['message' => 'Reward is not currently available.']);

        $gold = MembershipLevel::where('code', 'GOLD')->first();
        $this->redeem($customer->id, $this->reward(['min_membership_level_id' => $gold->id])->id, 'k-level')
            ->assertUnprocessable()->assertJsonFragment(['message' => 'Requires Gold Member membership or higher.']);

        $limited = $this->reward(['points_required' => 10, 'max_per_customer' => 1]);
        $this->redeem($customer->id, $limited->id, 'k-limit-1')->assertCreated();
        $this->redeem($customer->id, $limited->id, 'k-limit-2')
            ->assertUnprocessable()->assertJsonFragment(['message' => 'Limit of 1 per customer reached.']);

        $this->assertSame(140, $customer->membership()->first()->points_balance);
    }

    public function test_non_members_and_suspended_accounts_cannot_redeem(): void
    {
        $nonMember = $this->activeCustomer([], false);
        $reward = $this->reward(['points_required' => 1]);
        $this->actingAsRole('Customer Service');

        $this->redeem($nonMember->id, $reward->id, 'k-nonmember')
            ->assertUnprocessable()->assertJsonFragment(['message' => 'Customer is not enrolled in the loyalty program.']);

        $member = $this->activeCustomer(['phone' => '09001112222', 'email' => 'm2@example.com', 'full_name' => 'Member Two']);
        $this->setPoints($member, 10);
        $member->update(['status' => 'Suspended']);
        $this->redeem($member->id, $reward->id, 'k-suspended')
            ->assertUnprocessable()->assertJsonFragment(['message' => 'Customer account status (Suspended) is not eligible for redemption.']);
    }

    public function test_cancellation_refunds_points_and_restores_stock(): void
    {
        $customer = $this->activeCustomer();
        $this->setPoints($customer, 100);
        $reward = $this->reward(['stock_quantity' => 1]);

        $this->actingAsRole('Customer Service');
        $id = $this->redeem($customer->id, $reward->id, 'k-cancel')->assertCreated()->json('data.id');
        $this->postJson("/api/redemptions/{$id}/cancel", ['reason' => 'Reward out of stock at branch'])->assertForbidden();

        $this->actingAsRole('Manager');
        $this->postJson("/api/redemptions/{$id}/cancel", ['reason' => 'Reward out of stock at branch'])
            ->assertOk()->assertJsonPath('data.status', 'Cancelled');
        $this->postJson("/api/redemptions/{$id}/cancel", ['reason' => 'Second attempt'])->assertUnprocessable();

        $this->assertSame(100, $customer->membership()->first()->points_balance);
        $this->assertSame(1, $reward->fresh()->stock_quantity);
        $this->assertDatabaseHas('loyalty_transactions', ['reward_redemption_id' => $id, 'type' => 'refund', 'points' => 100]);
    }

    public function test_customer_reward_list_shows_eligibility(): void
    {
        $customer = $this->activeCustomer();
        $this->setPoints($customer, 100);
        $this->reward(['points_required' => 50, 'name' => 'Affordable']);
        $this->reward(['points_required' => 500, 'name' => 'Expensive']);

        $this->actingAsRole('Customer Service');
        $rewards = collect($this->getJson("/api/customers/{$customer->id}/rewards")->assertOk()->json('data.rewards'))->keyBy('name');

        $this->assertTrue($rewards['Affordable']['eligible']);
        $this->assertFalse($rewards['Expensive']['eligible']);
    }

    public function test_reward_catalogue_management_is_restricted(): void
    {
        $payload = ['code' => 'ECO-BAG', 'name' => 'Eco Bag', 'points_required' => 50, 'is_active' => true];

        $this->actingAsRole('Customer Service');
        $this->postJson('/api/rewards', $payload)->assertForbidden();

        $this->actingAsRole('Manager');
        $id = $this->postJson('/api/rewards', $payload)->assertCreated()->json('data.id');
        $this->deleteJson("/api/rewards/{$id}")->assertOk();
        $this->assertSoftDeleted('rewards', ['id' => $id]);
    }
}
