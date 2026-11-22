<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Loyalty reward redemption processing (Module 5).
 */
class RedemptionController extends Controller
{
    public function __construct(private LoyaltyService $loyalty)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $redemptions = RewardRedemption::with(['customer:id,customer_code,full_name', 'reward:id,code,name', 'branch:id,name', 'processedBy:id,name', 'cancelledBy:id,name'])
            ->when($request->customer_id, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->reward_id, fn ($q, $v) => $q->where('reward_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('redemption_number', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->search($s))))
            ->when($request->start_date, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->end_date, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 15));

        return $this->ok($redemptions);
    }

    /**
     * Validate eligibility without redeeming (step 3-4 of the workflow).
     */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'reward_id' => 'required|integer|exists:rewards,id',
            'quantity' => 'nullable|integer|min:1|max:100',
        ]);

        $customer = Customer::findOrFail($data['customer_id']);
        $reward = Reward::findOrFail($data['reward_id']);
        $quantity = $data['quantity'] ?? 1;
        $blocker = $this->loyalty->redemptionBlocker($customer, $reward, $quantity);

        return $this->ok([
            'eligible' => $blocker === null,
            'reason' => $blocker,
            'points_required' => $reward->points_required * $quantity,
            'points_balance' => $this->loyalty->membershipFor($customer)->points_balance,
        ]);
    }

    /**
     * Confirm the redemption: points are verified and deducted on the server
     * inside a database transaction.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'reward_id' => 'required|integer|exists:rewards,id',
            'quantity' => 'nullable|integer|min:1|max:100',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'notes' => 'nullable|string|max:500',
            'idempotency_key' => 'required|string|min:8|max:64',
        ]);

        $result = $this->loyalty->redeem(
            Customer::findOrFail($data['customer_id']),
            Reward::findOrFail($data['reward_id']),
            $data['quantity'] ?? 1,
            $request->user(),
            $data
        );

        $redemption = $result['redemption']->load(['customer:id,customer_code,full_name', 'reward', 'branch:id,name', 'processedBy:id,name']);

        return $this->ok(
            array_merge($redemption->toArray(), [
                'points_balance' => $this->loyalty->membershipFor($redemption->customer)->points_balance,
                'duplicate_request' => $result['duplicate'],
            ]),
            $result['duplicate'] ? 'This redemption was already processed' : 'Redemption confirmed',
            $result['duplicate'] ? 200 : 201
        );
    }

    public function show(RewardRedemption $redemption): JsonResponse
    {
        return $this->ok($redemption->load(['customer:id,customer_code,membership_number,full_name', 'reward', 'branch:id,name', 'processedBy:id,name', 'cancelledBy:id,name']));
    }

    public function cancel(Request $request, RewardRedemption $redemption): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:255']);

        $redemption = $this->loyalty->cancelRedemption($redemption, $data['reason'], $request->user());

        return $this->ok($redemption, 'Redemption cancelled and points refunded');
    }
}
