<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\LoyaltySetting;
use App\Models\LoyaltyTransaction;
use App\Models\MembershipLevel;
use App\Models\Order;
use App\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Loyalty membership and points management (Module 2).
 */
class LoyaltyController extends Controller
{
    public function __construct(private LoyaltyService $loyalty)
    {
    }

    // ---------------- Settings & levels ----------------

    public function settings(): JsonResponse
    {
        return $this->ok([
            'settings' => $this->loyalty->settings(),
            'is_configured' => $this->loyalty->isConfigured(),
            'earning_order_status' => config('shoplink.loyalty_earning_order_status'),
            'last_updated' => LoyaltySetting::with('updatedBy:id,name')->orderByDesc('updated_at')->first()?->only(['updated_at', 'updatedBy']),
            'levels' => MembershipLevel::orderBy('rank')->withCount('memberships')->get(),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'program_enabled' => 'required|boolean',
            'amount_per_point' => 'nullable|numeric|min:0.01|max:1000000',
            'minimum_purchase_amount' => 'required|numeric|min:0|max:1000000',
            'points_expiry_months' => 'nullable|integer|min:1|max:120',
            'auto_upgrade_enabled' => 'required|boolean',
        ]);

        $settings = $this->loyalty->updateSettings($data, $request->user());

        return $this->ok($settings, 'Loyalty settings saved');
    }

    public function updateLevel(Request $request, MembershipLevel $level): JsonResponse
    {
        $data = $request->validate([
            'min_lifetime_points' => 'nullable|integer|min:1|max:100000000',
            'points_multiplier' => 'required|numeric|min:0|max:10',
            'description' => 'nullable|string|max:255',
            'benefits' => 'nullable|string|max:2000',
            'is_active' => 'required|boolean',
        ]);

        // Base levels are assigned by the account workflow, not by points.
        if (in_array($level->code, [MembershipLevel::REGULAR, MembershipLevel::REGISTERED, MembershipLevel::LOYALTY], true)) {
            $data['min_lifetime_points'] = null;
        }

        // Thresholds must increase with rank so upgrades are unambiguous.
        if (!empty($data['min_lifetime_points'])) {
            $conflict = MembershipLevel::whereNotNull('min_lifetime_points')
                ->where('id', '!=', $level->id)
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('rank', '<', $level->rank)->where('min_lifetime_points', '>=', $data['min_lifetime_points']))
                    ->orWhere(fn ($q) => $q->where('rank', '>', $level->rank)->where('min_lifetime_points', '<=', $data['min_lifetime_points'])))
                ->exists();
            if ($conflict) {
                throw new BusinessRuleException('Upgrade thresholds must increase with membership rank.');
            }
        }

        $level->update($data);

        return $this->ok($level->fresh(), "{$level->name} level updated");
    }

    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0',
            'membership_level_id' => 'nullable|integer|exists:membership_levels,id',
        ]);

        $level = isset($data['membership_level_id']) ? MembershipLevel::find($data['membership_level_id']) : null;

        return $this->ok([
            'points' => $this->loyalty->calculatePoints((float) $data['amount'], $level),
            'is_configured' => $this->loyalty->isConfigured(),
        ]);
    }

    // ---------------- Members ----------------

    public function members(Request $request): JsonResponse
    {
        $levels = MembershipLevel::where('is_active', true)->orderBy('rank')->get();

        $members = CustomerMembership::with(['customer:id,customer_code,membership_number,full_name,phone,status,preferred_branch_id', 'level'])
            ->whereHas('customer', fn ($q) => $q->where('status', '!=', 'Archived')->search($request->search))
            ->when($request->membership_level_id, fn ($q, $v) => $q->where('membership_level_id', $v))
            ->when($request->get('enrolled', 'yes') === 'yes', fn ($q) => $q->whereNotNull('loyalty_enrolled_at'))
            ->when($request->get('enrolled') === 'no', fn ($q) => $q->whereNull('loyalty_enrolled_at'))
            ->when($request->boolean('near_upgrade'), function ($q) use ($levels) {
                // Within 20% of the next configured threshold.
                $q->where(function ($q) use ($levels) {
                    foreach ($levels->whereNotNull('min_lifetime_points') as $level) {
                        $q->orWhere(fn ($q) => $q
                            ->whereHas('level', fn ($l) => $l->where('rank', '<', $level->rank))
                            ->where('lifetime_points_earned', '>=', (int) floor($level->min_lifetime_points * 0.8))
                            ->where('lifetime_points_earned', '<', $level->min_lifetime_points));
                    }
                });
            })
            ->orderBy(match ($request->get('sort')) {
                'points' => 'points_balance',
                'lifetime' => 'lifetime_points_earned',
                default => 'loyalty_enrolled_at',
            }, 'desc')
            ->paginate($this->perPage($request))
            ->through(function (CustomerMembership $m) use ($levels) {
                $next = $levels->first(fn ($l) => $l->rank > $m->level->rank && $l->min_lifetime_points !== null);

                return array_merge($m->toArray(), [
                    'next_level' => $next ? [
                        'name' => $next->name,
                        'min_lifetime_points' => $next->min_lifetime_points,
                        'points_needed' => max(0, $next->min_lifetime_points - $m->lifetime_points_earned),
                    ] : null,
                ]);
            });

        return $this->ok($members);
    }

    public function customerLoyalty(Request $request, Customer $customer): JsonResponse
    {
        $membership = $this->loyalty->membershipFor($customer)->load(['level', 'enrolledBy:id,name']);
        $next = MembershipLevel::where('is_active', true)
            ->whereNotNull('min_lifetime_points')
            ->where('rank', '>', $membership->level->rank)
            ->orderBy('rank')
            ->first();

        $transactions = $customer->loyaltyTransactions()
            ->with(['order:id,transaction_number', 'redemption:id,redemption_number', 'performedBy:id,name', 'authorizedBy:id,name'])
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 15));

        return $this->ok([
            'membership' => $membership,
            'next_level' => $next ? [
                'name' => $next->name,
                'min_lifetime_points' => $next->min_lifetime_points,
                'points_needed' => max(0, $next->min_lifetime_points - $membership->lifetime_points_earned),
            ] : null,
            'can_transact' => $customer->canTransact(),
            'program_configured' => $this->loyalty->isConfigured(),
            'transactions' => $transactions,
        ]);
    }

    public function enroll(Request $request, Customer $customer): JsonResponse
    {
        $membership = $this->loyalty->enroll($customer, $request->user());

        return $this->ok($membership, 'Customer enrolled in the loyalty program', 201);
    }

    public function setLevel(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'membership_level_id' => 'required|integer|exists:membership_levels,id',
            'reason' => 'required|string|max:255',
            'lock' => 'sometimes|boolean',
        ]);

        $membership = $this->loyalty->setLevel(
            $customer,
            MembershipLevel::findOrFail($data['membership_level_id']),
            $data['reason'],
            $request->boolean('lock', true),
            $request->user()
        );

        return $this->ok($membership, 'Membership level updated');
    }

    // ---------------- Ledger ----------------

    public function transactions(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', Rule::in([LoyaltyTransaction::EARN, LoyaltyTransaction::REDEEM, LoyaltyTransaction::ADJUSTMENT, LoyaltyTransaction::REVERSAL, LoyaltyTransaction::REFUND, LoyaltyTransaction::EXPIRY])],
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $transactions = LoyaltyTransaction::with(['customer:id,customer_code,full_name', 'order:id,transaction_number', 'redemption:id,redemption_number', 'performedBy:id,name', 'authorizedBy:id,name'])
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->customer_id, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q
                ->whereHas('customer', fn ($c) => $c->search($s))
                ->orWhere('description', 'like', "%{$s}%")))
            ->when($request->start_date, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->end_date, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 15));

        $totals = LoyaltyTransaction::query()
            ->when($request->start_date, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->end_date, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->selectRaw('type, SUM(points) as points, COUNT(*) as count')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        return $this->ok(['transactions' => $transactions, 'totals' => $totals]);
    }

    public function adjust(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'points' => 'required|integer|not_in:0|min:-1000000|max:1000000',
            'reason' => 'required|string|min:5|max:500',
        ]);

        $transaction = $this->loyalty->adjust($customer, (int) $data['points'], $data['reason'], $request->user());

        return $this->ok($transaction, 'Points adjusted', 201);
    }

    /**
     * Post points for a completed order that did not earn (e.g. the customer
     * was linked or enrolled afterwards). Idempotent per order.
     */
    public function syncOrder(Order $order): JsonResponse
    {
        $result = $this->loyalty->awardForOrder($order);

        if (!$result['awarded']) {
            throw new BusinessRuleException($result['reason']);
        }

        return $this->ok($result['transaction'], "{$result['transaction']->points} points posted for {$order->transaction_number}");
    }

    public function runExpiry(): JsonResponse
    {
        if (!$this->loyalty->settings()['points_expiry_months']) {
            throw new BusinessRuleException('Points expiry is not configured.');
        }

        $count = $this->loyalty->expireInactiveBalances();

        return $this->ok(['expired_accounts' => $count], "Expired points on {$count} account(s)");
    }
}
