<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CustomerAccountLogger;
use App\Services\LoyaltyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer purchase history (Module 3). Reads the existing orders and
 * order_items records; no purchase data is duplicated.
 */
class PurchaseHistoryController extends Controller
{
    public function __construct(private LoyaltyService $loyalty)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'linked' => 'nullable|in:customer,walk_in',
        ]);

        $orders = $this->filtered($request, Order::query())
            ->when($request->customer_id, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->linked === 'customer', fn ($q) => $q->whereNotNull('customer_id'))
            ->when($request->linked === 'walk_in', fn ($q) => $q->whereNull('customer_id'))
            ->paginate($this->perPage($request))
            ->through(fn ($order) => $this->present($order));

        return $this->ok($orders);
    }

    public function forCustomer(Request $request, Customer $customer): JsonResponse
    {
        $orders = $this->filtered($request, $customer->orders()->getQuery())
            ->paginate($this->perPage($request))
            ->through(fn ($order) => $this->present($order));

        $completed = $customer->orders()->where('order_status', '!=', 'Cancelled');

        $summary = [
            'total_transactions' => (clone $completed)->count(),
            'total_spent' => round((float) (clone $completed)->sum('total_amount'), 2),
            'average_transaction' => round((float) (clone $completed)->avg('total_amount'), 2),
            'first_purchase_at' => (clone $completed)->min('order_date'),
            'last_purchase_at' => (clone $completed)->max('order_date'),
            'top_products' => OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->where('orders.customer_id', $customer->id)
                ->where('orders.order_status', '!=', 'Cancelled')
                ->groupBy('products.id', 'products.product_name')
                ->orderByDesc('total_quantity')
                ->limit(5)
                ->get(['products.id', 'products.product_name', DB::raw('SUM(order_items.quantity) as total_quantity')]),
        ];

        return $this->ok(['summary' => $summary, 'orders' => $orders]);
    }

    /**
     * Transaction verification: checks that recorded totals, items and loyalty
     * postings are consistent so staff can investigate discrepancies.
     */
    public function verify(Order $order): JsonResponse
    {
        $order->load(['customer.membership.level', 'orderItems.product', 'branch', 'user']);
        $checks = [];

        $itemsTotal = round((float) $order->orderItems->sum(fn ($i) => $i->quantity * $i->unit_price), 2);
        $checks[] = [
            'check' => 'Order total matches line items',
            'passed' => abs($itemsTotal - (float) $order->total_amount) < 0.01,
            'detail' => "Recorded total {$order->total_amount}; sum of items {$itemsTotal}",
        ];

        $checks[] = [
            'check' => 'Transaction has line items',
            'passed' => $order->orderItems->isNotEmpty(),
            'detail' => $order->orderItems->count() . ' item(s)',
        ];

        $checks[] = [
            'check' => 'Associated with a customer account',
            'passed' => $order->customer_id !== null,
            'detail' => $order->customer ? "{$order->customer->customer_code} - {$order->customer->full_name}" : 'Walk-in / no customer ID provided',
        ];

        $ledger = LoyaltyTransaction::where('order_id', $order->id)->get();
        $earned = $ledger->where('type', LoyaltyTransaction::EARN)->sum('points');
        $reversed = abs($ledger->where('type', LoyaltyTransaction::REVERSAL)->sum('points'));
        $isEarningStatus = $order->order_status === config('shoplink.loyalty_earning_order_status');
        $eligible = $order->customer && $order->customer->membership?->loyalty_enrolled_at && $this->loyalty->isConfigured();

        $expected = $eligible && $isEarningStatus ? $this->loyalty->calculatePoints((float) $order->total_amount, $order->customer->membership->level) : 0;

        $loyaltyPassed = match (true) {
            $order->order_status === 'Cancelled' => $earned === $reversed || $earned === 0,
            !$eligible || !$isEarningStatus => $earned === 0,
            default => $earned > 0,
        };

        $checks[] = [
            'check' => 'Loyalty points posting is consistent',
            'passed' => $loyaltyPassed,
            'detail' => "Earned {$earned}, reversed {$reversed}" . ($expected ? "; expected under current rules: {$expected}" : '')
                . (!$loyaltyPassed && $eligible && $isEarningStatus ? ' - points can be synced from Loyalty Points.' : ''),
        ];

        return $this->ok([
            'order' => $this->present($order),
            'checks' => $checks,
            'all_passed' => collect($checks)->every('passed'),
            'can_sync_points' => $eligible && $isEarningStatus && $earned === 0,
        ]);
    }

    /**
     * Link a walk-in transaction to the customer who made it (e.g. a customer
     * who forgot their membership card), then award points if eligible.
     */
    public function associate(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'reason' => 'required|string|max:255',
        ]);

        if ($order->customer_id) {
            throw new BusinessRuleException('This transaction is already associated with a customer.');
        }

        $customer = Customer::findOrFail($data['customer_id']);
        if (in_array($customer->status, ['Blocked', 'Archived'], true)) {
            throw new BusinessRuleException("Cannot associate purchases with a {$customer->status} account.");
        }

        $order->update(['customer_id' => $customer->id]);

        CustomerAccountLogger::log($customer, 'purchase_associated', "Transaction {$order->transaction_number} associated with the account.", [
            'order_id' => $order->id,
            'reason' => $data['reason'],
        ]);

        $award = $this->loyalty->awardForOrder($order->fresh());

        return $this->ok([
            'order' => $this->present($order->fresh()),
            'points' => ['awarded' => $award['awarded'], 'reason' => $award['reason'], 'points' => $award['transaction']?->points],
        ], 'Transaction associated with customer');
    }

    private function filtered(Request $request, Builder $query): Builder
    {
        return $query
            ->with(['customer:id,customer_code,full_name,phone', 'branch:id,code,name', 'user:id,name', 'orderItems.product:id,product_name,sku', 'loyaltyTransactions'])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('transaction_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($c) => $c->search($search))
                        ->orWhereHas('orderItems.product', fn ($p) => $p->where('product_name', 'like', "%{$search}%"));
                });
            })
            ->when($request->status, fn ($q, $v) => $q->where('order_status', $v))
            ->when($request->branch_id, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($request->payment_method, fn ($q, $v) => $q->where('payment_method', $v))
            ->when($request->start_date, fn ($q, $v) => $q->whereDate('order_date', '>=', $v))
            ->when($request->end_date, fn ($q, $v) => $q->whereDate('order_date', '<=', $v))
            ->orderByDesc('order_date')
            ->orderByDesc('id');
    }

    private function present(Order $order): array
    {
        return [
            'id' => $order->id,
            'transaction_number' => $order->transaction_number,
            'order_date' => $order->order_date,
            'order_status' => $order->order_status,
            'payment_method' => $order->payment_method,
            'total_amount' => (float) $order->total_amount,
            'customer' => $order->customer?->only(['id', 'customer_code', 'full_name', 'phone']),
            'branch' => $order->branch?->only(['id', 'code', 'name']),
            'cashier' => $order->user?->only(['id', 'name']),
            'items' => $order->orderItems->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->product_name,
                'sku' => $item->product?->sku,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => round($item->quantity * (float) $item->unit_price, 2),
            ]),
            'items_count' => $order->orderItems->sum('quantity'),
            'points_earned' => (int) $order->loyaltyTransactions->whereIn('type', [LoyaltyTransaction::EARN, LoyaltyTransaction::REVERSAL])->sum('points'),
        ];
    }
}
