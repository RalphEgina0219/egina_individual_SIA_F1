<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerAccountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
  public function __construct(private CustomerAccountService $accounts)
  {
  }

  /**
   * Display a listing of customers (search, filters, pagination)
   */
  public function index(Request $request): JsonResponse
  {
    $request->validate([
      'status' => ['nullable', Rule::in(config('shoplink.customer_statuses'))],
      'customer_type' => ['nullable', Rule::in(config('shoplink.customer_types'))],
      'branch_id' => 'nullable|integer',
      'membership_level_id' => 'nullable|integer',
      'loyalty' => 'nullable|in:enrolled,not_enrolled',
      'registered_from' => 'nullable|date',
      'registered_to' => 'nullable|date',
      'inactive_days' => 'nullable|integer|min:1|max:3650',
      'sort' => 'nullable|in:newest,oldest,name,last_purchase,total_spent',
    ]);

    $query = $this->withPurchaseStats(Customer::with(['membership.level', 'preferredBranch']))
      ->search($request->search)
      ->when($request->status, fn ($q, $v) => $q->where('status', $v))
      ->when(!$request->status && !$request->boolean('include_archived'), fn ($q) => $q->where('status', '!=', 'Archived'))
      ->when($request->customer_type, fn ($q, $v) => $q->where('customer_type', $v))
      ->when($request->branch_id, fn ($q, $v) => $q->where('preferred_branch_id', $v))
      ->when($request->membership_level_id, fn ($q, $v) => $q->whereHas('membership', fn ($m) => $m->where('membership_level_id', $v)))
      ->when($request->loyalty === 'enrolled', fn ($q) => $q->whereHas('membership', fn ($m) => $m->whereNotNull('loyalty_enrolled_at')))
      ->when($request->loyalty === 'not_enrolled', fn ($q) => $q->whereDoesntHave('membership', fn ($m) => $m->whereNotNull('loyalty_enrolled_at')))
      ->when($request->registered_from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
      ->when($request->registered_to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
      // P10: customers with no purchase in the last N days
      ->when($request->inactive_days, fn ($q, $days) => $q->whereDoesntHave('orders', fn ($o) => $o->where('order_date', '>=', now()->subDays($days))));

    match ($request->get('sort', 'newest')) {
      'oldest' => $query->orderBy('id'),
      'name' => $query->orderBy('full_name'),
      'last_purchase' => $query->orderByDesc('last_purchase_at'),
      'total_spent' => $query->orderByDesc('total_spent'),
      default => $query->orderByDesc('id'),
    };

    $customers = $query->paginate($this->perPage($request))
      ->through(fn ($customer) => (new CustomerResource($customer))->resolve($request));

    return response()->json([
      'success' => true,
      'data' => $customers
    ]);
  }

  /**
   * Quick lookup used at the point of sale and in redemption / feedback forms
   */
  public function lookup(Request $request): JsonResponse
  {
    $request->validate(['q' => 'required|string|min:1|max:100']);

    $customers = Customer::with('membership.level')
      ->search($request->q)
      ->where('status', '!=', 'Archived')
      ->orderBy('full_name')
      ->limit(15)
      ->get()
      ->map(fn ($c) => [
        'id' => $c->id,
        'customer_code' => $c->customer_code,
        'membership_number' => $c->membership_number,
        'full_name' => $c->full_name,
        'phone' => $c->phone,
        'email' => $c->email,
        'status' => $c->status,
        'can_transact' => $c->canTransact(),
        'membership_level' => $c->membership?->level?->name,
        'is_loyalty_member' => (bool) $c->membership?->loyalty_enrolled_at,
        'points_balance' => $c->membership?->points_balance ?? 0,
      ]);

    return $this->ok($customers);
  }

  /**
   * Check for possible duplicate accounts before registration / update
   */
  public function checkDuplicates(Request $request): JsonResponse
  {
    $data = $request->validate([
      'full_name' => 'nullable|string|max:100',
      'phone' => 'nullable|string|max:20',
      'email' => 'nullable|string|max:100',
      'birthday' => 'nullable|date',
      'except_id' => 'nullable|integer',
    ]);

    $duplicates = $this->accounts->findDuplicates($data, $data['except_id'] ?? null);

    return $this->ok(CustomerAccountService::presentDuplicates($duplicates));
  }

  /**
   * Register a new customer account (Module 1)
   */
  public function store(StoreCustomerRequest $request): JsonResponse
  {
    $customer = $this->accounts->register($request->customerData(), $request->user(), $request->safe()->only([
      'confirm_not_duplicate', 'identity_verified', 'verification_method', 'activate', 'enroll_loyalty',
    ]));

    $customer->load(['membership.level', 'preferredBranch']);

    return $this->ok(new CustomerResource($customer), 'Customer registered successfully', 201);
  }

  /**
   * Display the customer profile
   */
  public function show(Request $request, Customer $customer): JsonResponse
  {
    $customer = $this->withPurchaseStats(Customer::whereKey($customer->id))
      ->with(['membership.level', 'preferredBranch', 'registeredBy', 'verifiedBy'])
      ->withCount(['feedback', 'redemptions'])
      ->firstOrFail();

    $data = (new CustomerResource($customer))->resolve($request);
    $data['feedback_count'] = $customer->feedback_count;
    $data['redemptions_count'] = $customer->redemptions_count;

    return $this->ok($data);
  }

  /**
   * Update the customer account (Module 6)
   */
  public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
  {
    if ($customer->status === 'Archived') {
      throw new BusinessRuleException('Archived accounts cannot be edited. Restore the account status first.');
    }

    $customer = $this->accounts->update($customer, $request->customerData(), $request->user(), $request->context());
    $customer->load(['membership.level', 'preferredBranch']);

    return $this->ok(new CustomerResource($customer), 'Customer updated successfully');
  }

  /**
   * Remove the customer. Accounts with any history must be archived instead.
   */
  public function destroy(Customer $customer): JsonResponse
  {
    $hasHistory = $customer->orders()->exists()
      || $customer->loyaltyTransactions()->exists()
      || $customer->redemptions()->exists()
      || $customer->feedback()->exists();

    if ($hasHistory) {
      return response()->json([
        'success' => false,
        'message' => 'Cannot delete a customer with purchase, loyalty or feedback history. Archive the account instead.'
      ], 400);
    }

    $customer->delete();

    return response()->json([
      'success' => true,
      'message' => 'Customer deleted successfully'
    ]);
  }

  private function withPurchaseStats(Builder $query): Builder
  {
    return $query
      ->withCount(['orders' => fn ($q) => $q->where('order_status', '!=', 'Cancelled')])
      ->withSum(['orders as total_spent' => fn ($q) => $q->where('order_status', '!=', 'Cancelled')], 'total_amount')
      ->withMax('orders as last_purchase_at', 'order_date');
  }
}
