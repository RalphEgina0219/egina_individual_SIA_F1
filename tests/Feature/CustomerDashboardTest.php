<?php

namespace Tests\Feature;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CustomerManagementHelpers;
use Tests\TestCase;

class CustomerDashboardTest extends TestCase
{
    use RefreshDatabase, CustomerManagementHelpers;

    public function test_dashboard_statistics_reflect_database_records(): void
    {
        $this->configureLoyalty(100);
        $member = $this->activeCustomer();
        $this->activeCustomer(['phone' => '09001112222', 'email' => 'b@example.com', 'full_name' => 'Second'], false);

        $this->actingAsRole('Customer Service');
        $this->postJson('/api/customers', $this->customerPayload(['phone' => '09003334444', 'email' => 'c@example.com', 'full_name' => 'Pending One']))->assertCreated();
        $this->postJson('/api/feedback', [
            'customer_id' => $member->id, 'type' => 'Complaint', 'channel' => 'In-store',
            'subject' => 'Long queue', 'message' => 'Waited 20 minutes.', 'category' => 'Checkout / Cashier',
        ])->assertCreated();

        $branch = Branch::create(['code' => 'CAL-MAIN', 'name' => 'Main']);
        $product = $this->stockedProduct(250);
        $this->actingAsRole('Sales');
        $orderId = $this->postJson('/api/orders', [
            'customer_id' => $member->id, 'branch_id' => $branch->id, 'payment_method' => 'Cash',
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->json('data.id');
        $this->putJson("/api/orders/{$orderId}", ['order_status' => 'Delivered']);

        $this->actingAsRole('Marketing');
        $response = $this->getJson('/api/customer-management/dashboard')->assertOk();

        $response->assertJsonPath('data.stats.total_customers', 3)
            ->assertJsonPath('data.stats.active_customers', 2)
            ->assertJsonPath('data.stats.pending_verification', 1)
            ->assertJsonPath('data.stats.loyalty_members', 1)
            ->assertJsonPath('data.stats.points_issued', 10)
            ->assertJsonPath('data.stats.points_redeemed', 0)
            ->assertJsonPath('data.stats.total_feedback', 1)
            ->assertJsonPath('data.stats.pending_complaints', 1)
            ->assertJsonPath('data.stats.resolved_complaints', 0)
            ->assertJsonPath('data.charts.feedback_categories.0.name', 'Checkout / Cashier');

        $today = collect($response->json('data.charts.purchase_activity'))->last();
        $this->assertEquals(1, $today['member_transactions']);
        $this->assertEquals(1000, $today['member_sales']);
        $this->assertCount(30, $response->json('data.charts.registration_trend'));

        // Branch filter
        $this->getJson("/api/customer-management/dashboard?branch_id={$branch->id}")
            ->assertOk()->assertJsonPath('data.recent_transactions.0.id', $orderId);
    }

    public function test_reports_include_segmentation_top_customers_and_inactive_customers(): void
    {
        $member = $this->activeCustomer();
        $this->activeCustomer(['phone' => '09001112222', 'email' => 'b@example.com', 'full_name' => 'Second'], false);
        $product = $this->stockedProduct(100);

        $this->actingAsRole('Sales');
        $this->postJson('/api/orders', [
            'customer_id' => $member->id, 'payment_method' => 'Cash',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated();

        $this->actingAsRole('Marketing');
        $this->getJson('/api/customer-management/reports?inactive_days=30')
            ->assertOk()
            ->assertJsonPath('data.top_customers.0.id', $member->id)
            ->assertJsonPath('data.top_customers.0.total_spent', 300)
            ->assertJsonPath('data.inactive_customers.count', 1)
            ->assertJsonPath('data.activity.member_transactions', 1);

        $this->getJson('/api/customer-management/dashboard?start_date=2025-01-01&end_date=2026-12-31')
            ->assertOk()->assertJsonPath('data.filters.granularity', 'month');
    }

    public function test_dashboard_access_is_role_restricted(): void
    {
        $this->actingAsRole('Sales');
        $this->getJson('/api/customer-management/dashboard')->assertForbidden();

        $this->actingAsRole('Customer Service');
        $this->getJson('/api/customer-management/dashboard')->assertOk();
        $this->getJson('/api/customer-management/options')->assertOk()->assertJsonPath('data.staff', []);
    }
}
