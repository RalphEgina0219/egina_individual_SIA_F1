<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Reward;
use App\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rewards catalogue management (Module 5).
 */
class RewardController extends Controller
{
    public function __construct(private LoyaltyService $loyalty)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rewards = Reward::with('minLevel:id,name,rank')
            ->withSum(['redemptions as redeemed_quantity' => fn ($q) => $q->where('status', 'Completed')], 'quantity')
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('points_required')
            ->paginate($this->perPage($request, 25))
            ->through(fn (Reward $r) => array_merge($r->toArray(), ['is_available' => $r->isAvailable()]));

        return $this->ok($rewards);
    }

    /**
     * Rewards with an eligibility verdict for a specific customer.
     */
    public function forCustomer(Customer $customer): JsonResponse
    {
        $rewards = Reward::with('minLevel:id,name,rank')
            ->where('is_active', true)
            ->orderBy('points_required')
            ->get()
            ->filter(fn (Reward $r) => $r->isAvailable())
            ->map(function (Reward $r) use ($customer) {
                $blocker = $this->loyalty->redemptionBlocker($customer, $r);

                return array_merge($r->toArray(), ['eligible' => $blocker === null, 'ineligible_reason' => $blocker]);
            })
            ->values();

        $membership = $this->loyalty->membershipFor($customer)->load('level');

        return $this->ok([
            'customer' => [
                'id' => $customer->id,
                'customer_code' => $customer->customer_code,
                'full_name' => $customer->full_name,
                'status' => $customer->status,
                'membership_level' => $membership->level->name,
                'is_loyalty_member' => $membership->is_loyalty_member,
                'points_balance' => $membership->points_balance,
            ],
            'rewards' => $rewards,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;

        return $this->ok(Reward::create($data)->load('minLevel'), 'Reward created successfully', 201);
    }

    public function show(Reward $reward): JsonResponse
    {
        return $this->ok(array_merge($reward->load('minLevel')->toArray(), ['is_available' => $reward->isAvailable()]));
    }

    public function update(Request $request, Reward $reward): JsonResponse
    {
        $reward->update($this->validated($request, $reward));

        return $this->ok($reward->fresh('minLevel'), 'Reward updated successfully');
    }

    /**
     * Soft delete so historical redemptions keep their reward reference.
     */
    public function destroy(Reward $reward): JsonResponse
    {
        $reward->update(['is_active' => false]);
        $reward->delete();

        return response()->json([
            'success' => true,
            'message' => 'Reward removed from the catalogue'
        ]);
    }

    private function validated(Request $request, ?Reward $reward = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('rewards', 'code')->ignore($reward?->id)],
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:2000',
            'points_required' => 'required|integer|min:1|max:10000000',
            'stock_quantity' => 'nullable|integer|min:0',
            'max_per_customer' => 'nullable|integer|min:1',
            'min_membership_level_id' => 'nullable|integer|exists:membership_levels,id',
            'is_active' => 'required|boolean',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
        ]);
    }
}
