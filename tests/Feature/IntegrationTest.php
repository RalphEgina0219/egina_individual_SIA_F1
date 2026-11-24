<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CustomerFeedback;
use App\Models\Employee;
use App\Models\IntegrationOutbox;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Integration\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\CustomerManagementHelpers;
use Tests\TestCase;

/**
 * Team integration (Bahagi A / B): API key, health, staff-performance,
 * outbox (SALE_OUT / SALE_RETURN / supplier issue), ProcessOutbox at HR sync.
 */
class IntegrationTest extends TestCase
{
    use RefreshDatabase, CustomerManagementHelpers;

    private const KEY = 'test-integration-key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'integration.api_key' => self::KEY,
            'integration.enabled' => false,
            'integration.systems.INV.url' => 'http://inv.test/api/integration/v1',
            'integration.systems.INV.key' => 'inv-key',
            'integration.systems.PROC.url' => 'http://proc.test/api/integration/v1',
            'integration.systems.PROC.key' => 'proc-key',
            'integration.systems.HR.url' => 'http://hr.test/api/integration/v1',
            'integration.systems.HR.key' => 'hr-key',
        ]);
    }

    private function api(string $uri)
    {
        return $this->withHeaders(['X-API-KEY' => self::KEY])->getJson("/api/integration/v1{$uri}");
    }

    private function invProduct(string $sku = 'SKU-0001', float $price = 320, int $stock = 100): Product
    {
        $product = Product::create(['product_name' => "Product {$sku}", 'unit_price' => $price, 'sku' => $sku]);
        $warehouse = Warehouse::firstOrCreate(['warehouse_name' => 'Main Warehouse'], ['location' => 'Calapan', 'warehouse_code' => 'WH-001']);
        Inventory::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => $stock]);

        return $product;
    }

    private function cashier(string $employeeNo = 'EMP-0003'): User
    {
        $user = $this->userWithRole('Sales', ['employee_no' => $employeeNo]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function sell(array $items, ?int $branchId = null): Order
    {
        $branchId ??= Branch::firstOrCreate(['code' => 'BR-001'], ['name' => 'Main Branch'])->id;

        $id = $this->postJson('/api/orders', [
            'branch_id' => $branchId,
            'payment_method' => 'Cash',
            'items' => $items,
        ])->assertCreated()->json('data.id');

        return Order::findOrFail($id);
    }

    // ---- A5 endpoints -------------------------------------------------

    public function test_health_needs_no_key(): void
    {
        $this->getJson('/api/integration/v1/health')
            ->assertOk()
            ->assertJson(['success' => true, 'system' => 'CRM', 'version' => 'v1'])
            ->assertJsonPath('time', fn ($time) => str_ends_with($time, '+08:00'));
    }

    public function test_missing_or_wrong_api_key_returns_401(): void
    {
        $this->getJson('/api/integration/v1/staff-performance?period=2026-11')
            ->assertUnauthorized()->assertJson(['success' => false]);

        $this->withHeaders(['X-API-KEY' => 'wrong'])->getJson('/api/integration/v1/staff-performance?period=2026-11')
            ->assertUnauthorized();

        $this->assertDatabaseHas('integration_logs', ['direction' => 'in', 'status_code' => 401]);
    }

    public function test_staff_performance_totals_sales_complaints_and_ratings(): void
    {
        $product = $this->invProduct('SKU-0001', 100);
        $this->cashier('EMP-0003');

        $this->sell([['product_id' => $product->id, 'quantity' => 2]]);   // 200
        $this->sell([['product_id' => $product->id, 'quantity' => 1]]);   // 100
        $cancelled = $this->sell([['product_id' => $product->id, 'quantity' => 5]]);
        $cancelled->update(['order_status' => 'Cancelled']);              // hindi kasama

        CustomerFeedback::create(['contact_name' => 'A', 'type' => 'Complaint', 'channel' => 'In-store', 'subject' => 'x', 'message' => 'x', 'received_at' => now(), 'rating' => 2, 'employee_no' => 'EMP-0003']);
        CustomerFeedback::create(['contact_name' => 'B', 'type' => 'General Feedback', 'channel' => 'In-store', 'subject' => 'y', 'message' => 'y', 'received_at' => now(), 'rating' => 5, 'employee_no' => 'EMP-0003']);

        $period = now('Asia/Manila')->format('Y-m');
        $this->api("/staff-performance?period={$period}")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0', [
                'employee_no' => 'EMP-0003',
                'period' => $period,
                'total_sales' => '300.00',
                'transaction_count' => 2,
                'complaint_count' => 1,
                'average_rating' => '3.50',
            ]);
    }

    public function test_staff_performance_month_follows_philippine_time(): void
    {
        $this->userWithRole('Sales', ['employee_no' => 'EMP-0003']);
        // Naka-UTC ang database. 15:30 UTC = 23:30 Nov 30 sa Manila; 16:30 UTC = 00:30 Dec 1 sa Manila.
        Order::create(['order_date' => '2026-11-30 15:30:00', 'total_amount' => 100, 'payment_method' => 'Cash', 'order_status' => 'Delivered', 'cashier_employee_no' => 'EMP-0003']);
        Order::create(['order_date' => '2026-11-30 16:30:00', 'total_amount' => 40, 'payment_method' => 'Cash', 'order_status' => 'Delivered', 'cashier_employee_no' => 'EMP-0003']);

        $this->api('/staff-performance?period=2026-11')->assertJsonPath('data.items.0.total_sales', '100.00');
        $this->api('/staff-performance?period=2026-12')->assertJsonPath('data.items.0.total_sales', '40.00');
    }

    public function test_dates_sent_to_other_systems_use_plus_eight(): void
    {
        $customer = $this->activeCustomer();
        $this->api("/customers/{$customer->customer_code}")
            ->assertJsonPath('data.updated_at', fn ($v) => str_ends_with($v, '+08:00'));

        // received_at sa database: 02:15 UTC → sa PROC: 10:15+08:00
        $product = $this->invProduct('SKU-0001');
        $this->actingAsRole('Customer Service');
        $id = $this->postJson('/api/feedback', [
            'contact_name' => 'Walk-in', 'type' => 'Complaint', 'channel' => 'In-store',
            'subject' => 'Broken', 'message' => 'x', 'product_id' => $product->id,
        ])->json('data.id');
        CustomerFeedback::whereKey($id)->update(['received_at' => '2026-11-23 02:15:00']);

        $this->postJson("/api/feedback/{$id}/forward-procurement", ['issue_type' => 'Defective', 'description' => 'x', 'quantity_affected' => 1])->assertOk();

        $this->assertSame('2026-11-23T10:15:00+08:00', IntegrationOutbox::where('endpoint', '/supplier-issues')->sole()->payload['reported_at']);
    }

    public function test_staff_performance_rejects_bad_period(): void
    {
        $this->api('/staff-performance?period=2026-13')
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonStructure(['errors' => ['period']]);
    }

    public function test_product_sales_groups_by_sku(): void
    {
        $rice = $this->invProduct('SKU-0001', 320);
        $this->cashier();
        $this->sell([['product_id' => $rice->id, 'quantity' => 3]]);

        $this->api('/product-sales?period=' . now('Asia/Manila')->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('data.items.0.sku', 'SKU-0001')
            ->assertJsonPath('data.items.0.quantity_sold', 3)
            ->assertJsonPath('data.items.0.total_sales', '960.00')
            ->assertJsonPath('data.items.0.transaction_count', 1);
    }

    public function test_customer_lookup_hides_private_fields(): void
    {
        $customer = $this->activeCustomer();

        $this->api("/customers/{$customer->customer_code}")
            ->assertOk()
            ->assertJsonPath('data.customer_code', $customer->customer_code)
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.birthday');

        $this->api('/customers/CUST-999999')->assertNotFound()->assertJson(['success' => false]);
    }

    // ---- F3: benta → INV ---------------------------------------------

    public function test_sale_puts_sale_out_in_outbox(): void
    {
        $product = $this->invProduct('SKU-0001');
        $this->cashier('EMP-0003');

        $order = $this->sell([['product_id' => $product->id, 'quantity' => 2]]);

        $this->assertSame('pending', $order->stock_sync_status);
        $this->assertSame('EMP-0003', $order->cashier_employee_no);

        $row = IntegrationOutbox::sole();
        $this->assertSame(['INV', 'POST', '/stock-movements', $order->transaction_number], [$row->target_system, $row->method, $row->endpoint, $row->reference_number]);
        $this->assertSame([
            'movement_type' => 'SALE_OUT',
            'source_system' => 'CRM',
            'reference_number' => $order->transaction_number,
            'branch_code' => 'BR-001',
            'warehouse_code' => null,
            'performed_by' => 'EMP-0003',
            'items' => [['sku' => 'SKU-0001', 'quantity' => 2]],
            'remarks' => 'Sale',
        ], $row->payload);

        // Ang lumang pagbawas ng stock ng CRM ay tuloy pa rin.
        $this->assertSame(98, (int) Inventory::where('product_id', $product->id)->sum('quantity'));
    }

    public function test_cancel_puts_sale_return_in_outbox(): void
    {
        $product = $this->invProduct('SKU-0001');
        $this->cashier();
        $order = $this->sell([['product_id' => $product->id, 'quantity' => 2]]);

        $this->actingAsRole('Manager');
        $this->putJson("/api/orders/{$order->id}", ['order_status' => 'Cancelled'])->assertOk();

        $return = Outbox::find('INV', '/stock-movements', "{$order->transaction_number}-RET");
        $this->assertNotNull($return);
        $this->assertSame('SALE_RETURN', $return->payload['movement_type']);
        $this->assertSame([['sku' => 'SKU-0001', 'quantity' => 2]], $return->payload['items']);
        $this->assertSame('pending', $order->fresh()->stock_sync_status);
    }

    public function test_product_without_sku_does_not_block_the_sale(): void
    {
        $product = $this->invProduct('OLD-CODE-1');
        $this->cashier();

        $order = $this->sell([['product_id' => $product->id, 'quantity' => 1]]);

        $this->assertSame('failed', $order->stock_sync_status);
        $this->assertSame(0, IntegrationOutbox::count());
        $this->assertDatabaseHas('integration_logs', ['reference_number' => $order->transaction_number, 'system' => 'INV']);
    }

    public function test_outbox_push_is_idempotent(): void
    {
        Outbox::push('INV', 'POST', '/stock-movements', ['a' => 1], 'TXN-1');
        Outbox::push('INV', 'POST', '/stock-movements', ['a' => 2], 'TXN-1');

        $this->assertSame(1, IntegrationOutbox::count());
        $this->assertSame(['a' => 1], IntegrationOutbox::first()->payload);
    }

    // ---- ProcessOutbox -------------------------------------------------

    public function test_process_outbox_sends_nothing_when_disabled(): void
    {
        Http::fake();
        Outbox::push('INV', 'POST', '/stock-movements', ['x' => 1], 'TXN-1');

        $this->artisan('integration:process-outbox')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('pending', IntegrationOutbox::first()->status);
    }

    public function test_process_outbox_marks_sent_and_syncs_the_order(): void
    {
        $product = $this->invProduct('SKU-0001');
        $this->cashier();
        $order = $this->sell([['product_id' => $product->id, 'quantity' => 1]]);

        config(['integration.enabled' => true]);
        Http::fake(['inv.test/*' => Http::response(['success' => true, 'data' => ['movement_number' => 'SM-20261123-0001', 'status' => 'posted']], 201)]);

        $this->artisan('integration:process-outbox')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === 'http://inv.test/api/integration/v1/stock-movements'
            && $request->hasHeader('X-API-KEY', 'inv-key')
            && $request['reference_number'] === $order->transaction_number);
        $this->assertSame('sent', IntegrationOutbox::first()->status);
        $this->assertSame('synced', $order->fresh()->stock_sync_status);
        $this->assertDatabaseHas('integration_logs', ['direction' => 'out', 'system' => 'INV', 'status_code' => 201]);
    }

    public function test_process_outbox_retries_with_backoff_then_fails(): void
    {
        config(['integration.enabled' => true]);
        Http::fake(['inv.test/*' => Http::response(['success' => false, 'message' => 'Down'], 500)]);
        $row = Outbox::push('INV', 'POST', '/stock-movements', ['x' => 1], 'TXN-1');

        $this->artisan('integration:process-outbox');
        $row->refresh();
        $this->assertSame(['pending', 1], [$row->status, $row->attempts]);
        $this->assertEqualsWithDelta(now()->addMinute()->timestamp, $row->next_attempt_at->timestamp, 5);

        // Hindi pa oras: hindi ipapadala.
        $this->artisan('integration:process-outbox');
        $this->assertSame(1, $row->fresh()->attempts);

        foreach ([2, 3, 4] as $expected) {
            IntegrationOutbox::whereKey($row->id)->update(['next_attempt_at' => now()->subSecond()]);
            $this->artisan('integration:process-outbox');
            $this->assertSame($expected, $row->fresh()->attempts);
        }
        $this->assertEqualsWithDelta(now()->addMinutes(8)->timestamp, $row->fresh()->next_attempt_at->timestamp, 5);

        IntegrationOutbox::whereKey($row->id)->update(['next_attempt_at' => now()->subSecond()]);
        $this->artisan('integration:process-outbox');
        $this->assertSame(['failed', 5], [$row->fresh()->status, $row->fresh()->attempts]);
    }

    public function test_process_outbox_fails_immediately_on_422(): void
    {
        $product = $this->invProduct('SKU-0001');
        $this->cashier();
        $order = $this->sell([['product_id' => $product->id, 'quantity' => 1]]);

        config(['integration.enabled' => true]);
        Http::fake(['inv.test/*' => Http::response(['success' => false, 'message' => 'Insufficient stock', 'errors' => ['items.0.quantity' => ['Only 0 left']]], 422)]);

        $this->artisan('integration:process-outbox');

        $row = IntegrationOutbox::first();
        $this->assertSame(['failed', 1], [$row->status, $row->attempts]);
        $this->assertStringContainsString('Insufficient stock', $row->last_error);
        $this->assertSame('failed', $order->fresh()->stock_sync_status);
    }

    public function test_sale_return_waits_for_sale_out_and_is_dropped_if_sale_out_failed(): void
    {
        config(['integration.enabled' => true]);
        $out = Outbox::push('INV', 'POST', '/stock-movements', ['x' => 1], 'TXN-9');
        $ret = Outbox::push('INV', 'POST', '/stock-movements', ['x' => 2], 'TXN-9-RET');
        $out->update(['next_attempt_at' => now()->addHour()]); // hindi pa naipapadala

        Http::fake();
        $this->artisan('integration:process-outbox');
        Http::assertNothingSent();
        $this->assertSame('pending', $ret->fresh()->status);

        $out->update(['status' => 'failed']);
        $this->artisan('integration:process-outbox');
        Http::assertNothingSent();
        $this->assertSame('failed', $ret->fresh()->status);
    }

    // ---- F7: Forward to Procurement ------------------------------------

    public function test_forward_complaint_to_procurement(): void
    {
        $product = $this->invProduct('SKU-0001');
        $branch = Branch::create(['code' => 'BR-001', 'name' => 'Main Branch']);
        $this->actingAsRole('Customer Service');

        $id = $this->postJson('/api/feedback', [
            'contact_name' => 'Walk-in', 'type' => 'Complaint', 'channel' => 'In-store',
            'subject' => 'Broken pack', 'message' => 'Rice bag torn', 'product_id' => $product->id,
            'branch_id' => $branch->id, 'employee_no' => 'EMP-0003',
        ])->assertCreated()->json('data.id');

        $feedback = CustomerFeedback::find($id);
        $this->assertMatchesRegularExpression('/^FB-\d{8}-\d{4,}$/', $feedback->feedback_number);
        $this->assertSame(['SKU-0001', 'EMP-0003'], [$feedback->sku, $feedback->employee_no]);

        $this->postJson("/api/feedback/{$id}/forward-procurement", [
            'issue_type' => 'Damaged Packaging', 'description' => 'Torn on delivery', 'quantity_affected' => 2,
        ])->assertOk();

        $row = Outbox::find('PROC', '/supplier-issues', $feedback->feedback_number);
        $this->assertSame('SKU-0001', $row->payload['sku']);
        $this->assertSame('BR-001', $row->payload['branch_code']);
        $this->assertSame('Damaged Packaging', $row->payload['issue_type']);
        $this->assertStringEndsWith('+08:00', $row->payload['reported_at']);
        $this->assertSame('pending', $feedback->fresh()->forward_status);

        // Hindi puwedeng i-forward nang dalawang beses.
        $this->postJson("/api/feedback/{$id}/forward-procurement", [
            'issue_type' => 'Other', 'description' => 'again', 'quantity_affected' => 1,
        ])->assertStatus(422);

        // Kapag naipadala: sent, at naitala ang issue_number ng PROC.
        config(['integration.enabled' => true]);
        Http::fake(['proc.test/*' => Http::response(['success' => true, 'data' => ['issue_number' => 'SI-20261123-0002', 'supplier_code' => 'SUP-0001', 'status' => 'Open']], 201)]);
        $this->artisan('integration:process-outbox');
        $this->assertSame(['sent', 'SI-20261123-0002', 'SUP-0001'], [$feedback->fresh()->forward_status, $feedback->fresh()->supplier_issue_number, $feedback->fresh()->supplier_code]);
    }

    public function test_complaint_without_inventory_sku_cannot_be_forwarded(): void
    {
        $this->actingAsRole('Customer Service');
        $id = $this->postJson('/api/feedback', [
            'contact_name' => 'Walk-in', 'type' => 'Complaint', 'channel' => 'In-store', 'subject' => 'Rude staff', 'message' => 'x',
        ])->json('data.id');

        $this->postJson("/api/feedback/{$id}/forward-procurement", [
            'issue_type' => 'Other', 'description' => 'x', 'quantity_affected' => 1,
        ])->assertStatus(422);
        $this->assertSame(0, IntegrationOutbox::count());
    }

    // ---- F1: login at HR status ------------------------------------------

    public function test_user_blocked_by_hr_cannot_log_in(): void
    {
        User::factory()->create(['email' => 'ana@example.com', 'password' => 'password123', 'role' => 'Sales', 'can_login' => false, 'employment_status' => 'Resigned']);

        $this->postJson('/api/login', ['email' => 'ana@example.com', 'password' => 'password123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn ($msg) => str_contains($msg, 'Resigned'));
    }

    public function test_hr_sync_blocks_resigned_employee_and_deletes_tokens(): void
    {
        $user = $this->userWithRole('Sales', ['employee_no' => 'EMP-0010']);
        $user->createToken('auth_token');

        config(['integration.enabled' => true]);
        Http::fake([
            'hr.test/api/integration/v1/employees*' => Http::response(['success' => true, 'data' => ['items' => [[
                'employee_no' => 'EMP-0010', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'email' => 'juan@example.com',
                'branch_code' => 'BR-001', 'department_code' => 'DEP-002', 'position' => 'Sales Associate',
                'employment_status' => 'Resigned', 'can_login' => false,
                'system_access' => [['system' => 'CRM', 'role' => 'Sales']], 'updated_at' => '2026-11-23T09:00:00+08:00',
            ]], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]]),
            'hr.test/*' => Http::response(['success' => true, 'data' => ['items' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]]]),
            '*' => Http::response(['success' => true, 'data' => ['items' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]]]),
        ]);

        $this->artisan('integration:sync-masterdata', ['--only' => 'HR'])->assertSuccessful();

        $user->refresh();
        $this->assertFalse($user->can_login);
        $this->assertSame('Resigned', $user->employment_status);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue(Employee::where('employee_no', 'EMP-0010')->exists());
        $this->assertDatabaseHas('integration_sync_cursors', ['resource' => 'HR/employees', 'last_updated_at' => '2026-11-23T09:00:00+08:00']);
    }

    // ---- Integrations page ----------------------------------------------

    public function test_integrations_page_is_admin_only_and_retry_requeues(): void
    {
        $row = Outbox::push('INV', 'POST', '/stock-movements', ['x' => 1], 'TXN-1');
        $row->update(['status' => 'failed', 'attempts' => 5, 'last_error' => 'boom']);

        $this->actingAsRole('Manager');
        $this->getJson('/api/integration-admin/outbox')->assertForbidden();

        $this->actingAsRole('Admin');
        $this->getJson('/api/integration-admin/outbox?status=failed')->assertOk()->assertJsonPath('data.total', 1);
        $this->postJson("/api/integration-admin/outbox/{$row->id}/retry")->assertOk();

        $this->assertSame(['pending', 0, null], [$row->fresh()->status, $row->fresh()->attempts, $row->fresh()->last_error]);
        $this->getJson('/api/integration-admin/logs')->assertOk();
    }
}
