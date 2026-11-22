<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerAccountService;
use App\Services\CustomerNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Account verification, activation, status management and account history
 * (Modules 1 and 6).
 */
class CustomerAccountController extends Controller
{
    public function __construct(
        private CustomerAccountService $accounts,
        private CustomerNotifier $notifier,
    ) {
    }

    public function verify(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'verification_method' => ['required', Rule::in(config('shoplink.verification_methods'))],
            'notes' => 'nullable|string|max:500',
        ]);

        $customer = $this->accounts->verify($customer, $data['verification_method'], $data['notes'] ?? null, $request->user());

        return $this->ok(new CustomerResource($customer->load('membership.level')), 'Customer identity verified');
    }

    public function activate(Request $request, Customer $customer): JsonResponse
    {
        $customer = $this->accounts->activate($customer, $request->user());

        return $this->ok(new CustomerResource($customer->load('membership.level')), 'Customer account activated');
    }

    public function changeStatus(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(config('shoplink.customer_statuses'))],
            'reason' => 'nullable|string|max:255',
        ]);

        $customer = $this->accounts->changeStatus($customer, $data['status'], $data['reason'] ?? null, $request->user());

        return $this->ok(new CustomerResource($customer->load('membership.level')), "Customer status changed to {$customer->status}");
    }

    public function accountLogs(Request $request, Customer $customer): JsonResponse
    {
        $logs = $customer->accountLogs()
            ->with('user:id,name,role')
            ->when($request->action, fn ($q, $v) => $q->where('action', $v))
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 20));

        return $this->ok($logs);
    }

    public function notifications(Request $request, Customer $customer): JsonResponse
    {
        $notifications = $customer->notifications()
            ->with('sender:id,name')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 20));

        return $this->ok($notifications);
    }

    /**
     * Send a promotional announcement to one customer or to a filtered segment.
     */
    public function announce(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => 'required|string|max:200',
            'message' => 'required|string|max:2000',
            'customer_ids' => 'nullable|array|max:500',
            'customer_ids.*' => 'integer|exists:customers,id',
            'segment' => 'nullable|array',
            'segment.membership_level_id' => 'nullable|integer|exists:membership_levels,id',
            'segment.branch_id' => 'nullable|integer|exists:branches,id',
            'segment.loyalty_members_only' => 'nullable|boolean',
        ]);

        $customers = Customer::query()
            ->whereIn('status', config('shoplink.transacting_statuses'))
            ->when($data['customer_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->when($data['segment']['membership_level_id'] ?? null, fn ($q, $v) => $q->whereHas('membership', fn ($m) => $m->where('membership_level_id', $v)))
            ->when($data['segment']['branch_id'] ?? null, fn ($q, $v) => $q->where('preferred_branch_id', $v))
            ->when($data['segment']['loyalty_members_only'] ?? false, fn ($q) => $q->whereHas('membership', fn ($m) => $m->whereNotNull('loyalty_enrolled_at')))
            ->limit(500)
            ->get();

        $results = $customers->map(fn ($c) => $this->notifier->send($c, 'promotional', $data['subject'], $data['message'])->status)->countBy();

        return $this->ok([
            'recipients' => $customers->count(),
            'results' => $results,
        ], "Announcement processed for {$customers->count()} customer(s)");
    }
}
