<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\LoyaltySetting;
use App\Models\LoyaltyTransaction;
use App\Models\MembershipLevel;
use App\Models\Order;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * All loyalty balance changes go through post(), which locks the member's
 * balance row, writes an immutable ledger entry and enforces unique
 * references so the same order / redemption can never post twice.
 */
class LoyaltyService
{
    private ?array $settingsCache = null;

    public function __construct(private CustomerNotifier $notifier)
    {
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    public function settings(): array
    {
        if ($this->settingsCache === null) {
            $raw = LoyaltySetting::pluck('value', 'key');

            $this->settingsCache = [
                'program_enabled' => (bool) ($raw['program_enabled'] ?? false),
                'amount_per_point' => isset($raw['amount_per_point']) && $raw['amount_per_point'] !== '' ? (float) $raw['amount_per_point'] : null,
                'minimum_purchase_amount' => (float) ($raw['minimum_purchase_amount'] ?? 0),
                'points_expiry_months' => isset($raw['points_expiry_months']) && $raw['points_expiry_months'] !== '' ? (int) $raw['points_expiry_months'] : null,
                'auto_upgrade_enabled' => (bool) ($raw['auto_upgrade_enabled'] ?? false),
            ];
        }

        return $this->settingsCache;
    }

    public function updateSettings(array $values, User $user): array
    {
        $before = $this->settings();

        $amountPerPoint = array_key_exists('amount_per_point', $values) ? $values['amount_per_point'] : $before['amount_per_point'];
        if (!empty($values['program_enabled']) && empty($amountPerPoint)) {
            throw new BusinessRuleException('Set the purchase amount per point before enabling points earning.');
        }

        foreach ($values as $key => $value) {
            $stored = is_bool($value) ? ($value ? '1' : '0') : ($value === null ? null : (string) $value);
            LoyaltySetting::updateOrCreate(['key' => $key], ['value' => $stored, 'updated_by' => $user->id]);
        }

        $this->settingsCache = null;

        return $this->settings();
    }

    public function isConfigured(): bool
    {
        $settings = $this->settings();

        return $settings['program_enabled'] && $settings['amount_per_point'] > 0;
    }

    public function calculatePoints(float $amount, ?MembershipLevel $level = null): int
    {
        $settings = $this->settings();

        if (!$this->isConfigured() || $amount < $settings['minimum_purchase_amount']) {
            return 0;
        }

        $multiplier = $level ? (float) $level->points_multiplier : 1.0;

        return (int) floor(($amount / $settings['amount_per_point']) * $multiplier);
    }

    // ------------------------------------------------------------------
    // Membership
    // ------------------------------------------------------------------

    public function membershipFor(Customer $customer): CustomerMembership
    {
        return CustomerMembership::firstOrCreate(
            ['customer_id' => $customer->id],
            [
                'membership_level_id' => MembershipLevel::byCode(MembershipLevel::REGULAR)->id,
                'level_assigned_at' => now(),
            ]
        );
    }

    public function enroll(Customer $customer, User $user): CustomerMembership
    {
        if (!$customer->canTransact()) {
            throw new BusinessRuleException("Only verified or active accounts can join the loyalty program (current status: {$customer->status}).");
        }

        $membership = DB::transaction(function () use ($customer, $user) {
            $membership = $this->membershipFor($customer);
            $membership = CustomerMembership::whereKey($membership->id)->lockForUpdate()->first();

            if ($membership->loyalty_enrolled_at) {
                throw new BusinessRuleException('Customer is already enrolled in the loyalty program.');
            }

            $loyaltyLevel = MembershipLevel::byCode(MembershipLevel::LOYALTY);
            $currentLevel = $membership->level;

            $membership->loyalty_enrolled_at = now();
            $membership->enrolled_by = $user->id;
            if ($currentLevel->rank < $loyaltyLevel->rank) {
                $membership->membership_level_id = $loyaltyLevel->id;
                $membership->level_assigned_at = now();
            }
            $membership->save();

            CustomerAccountLogger::log($customer, 'loyalty_enrolled', 'Enrolled in the loyalty program.', [
                'membership_level' => ['old' => $currentLevel->name, 'new' => $membership->fresh('level')->level->name],
            ]);

            return $membership;
        });

        $this->notifier->send(
            $customer,
            'welcome',
            'Welcome to the ShopLink Loyalty Program',
            "You are now a ShopLink loyalty member.\nMembership Number: {$customer->membership_number}\nEarn points on eligible purchases at any ShopLink branch."
        );

        return $membership->fresh('level');
    }

    /**
     * Ensure verified/active accounts are at least "Registered".
     */
    public function ensureRegisteredLevel(Customer $customer): void
    {
        $membership = $this->membershipFor($customer);
        $registered = MembershipLevel::byCode(MembershipLevel::REGISTERED);

        if ($membership->level->rank < $registered->rank) {
            $membership->update(['membership_level_id' => $registered->id, 'level_assigned_at' => now()]);
        }
    }

    public function setLevel(Customer $customer, MembershipLevel $level, string $reason, bool $lock, User $user): CustomerMembership
    {
        $membership = $this->membershipFor($customer);
        $old = $membership->level;

        if ($level->rank >= MembershipLevel::byCode(MembershipLevel::LOYALTY)->rank && !$membership->loyalty_enrolled_at) {
            throw new BusinessRuleException('Enroll the customer in the loyalty program before assigning a loyalty membership level.');
        }

        $membership->update([
            'membership_level_id' => $level->id,
            'level_assigned_at' => now(),
            'level_locked' => $lock,
        ]);

        CustomerAccountLogger::log($customer, 'membership_level_changed', "Membership level changed from {$old->name} to {$level->name}.", [
            'membership_level' => ['old' => $old->name, 'new' => $level->name],
            'reason' => $reason,
            'level_locked' => $lock,
        ]);

        if ($level->rank > $old->rank) {
            $this->notifier->send($customer, 'membership_upgrade', "You are now a {$level->name}", "Congratulations! Your ShopLink membership has been upgraded to {$level->name}.");
        }

        return $membership->fresh('level');
    }

    /**
     * Automatic upgrade based on lifetime points and configured thresholds.
     * Never downgrades and never overrides a manually locked level.
     */
    public function evaluateUpgrade(Customer $customer): ?MembershipLevel
    {
        $membership = $this->membershipFor($customer)->fresh('level');

        if (!$this->settings()['auto_upgrade_enabled'] || $membership->level_locked || !$membership->loyalty_enrolled_at) {
            return null;
        }

        $target = MembershipLevel::where('is_active', true)
            ->whereNotNull('min_lifetime_points')
            ->where('min_lifetime_points', '<=', $membership->lifetime_points_earned)
            ->where('rank', '>', $membership->level->rank)
            ->orderByDesc('rank')
            ->first();

        if (!$target) {
            return null;
        }

        $old = $membership->level;
        $membership->update(['membership_level_id' => $target->id, 'level_assigned_at' => now()]);

        CustomerAccountLogger::log($customer, 'membership_upgraded', "Automatically upgraded from {$old->name} to {$target->name}.", [
            'membership_level' => ['old' => $old->name, 'new' => $target->name],
            'lifetime_points_earned' => $membership->lifetime_points_earned,
        ]);

        $this->notifier->send($customer, 'membership_upgrade', "You are now a {$target->name}", "Congratulations! Your ShopLink membership has been upgraded to {$target->name}.");

        return $target;
    }

    // ------------------------------------------------------------------
    // Ledger
    // ------------------------------------------------------------------

    /**
     * Post a signed points movement. Must be the only writer of points_balance.
     */
    public function post(Customer $customer, int $points, string $type, string $description, array $attributes = []): LoyaltyTransaction
    {
        return DB::transaction(function () use ($customer, $points, $type, $description, $attributes) {
            $membership = $this->membershipFor($customer);
            $membership = CustomerMembership::whereKey($membership->id)->lockForUpdate()->first();

            $reference = $attributes['reference'] ?? null;
            if ($reference && LoyaltyTransaction::where('reference', $reference)->exists()) {
                throw new BusinessRuleException('This points transaction has already been recorded.', 409);
            }

            $newBalance = $membership->points_balance + $points;
            if ($newBalance < 0) {
                throw new BusinessRuleException("Insufficient points. Available balance: {$membership->points_balance}.");
            }

            $membership->points_balance = $newBalance;
            $membership->last_points_activity_at = now();

            match ($type) {
                LoyaltyTransaction::EARN => $membership->lifetime_points_earned += $points,
                LoyaltyTransaction::REVERSAL => $membership->lifetime_points_earned = max(0, $membership->lifetime_points_earned + $points),
                LoyaltyTransaction::REDEEM => $membership->lifetime_points_redeemed += abs($points),
                LoyaltyTransaction::REFUND => $membership->lifetime_points_redeemed = max(0, $membership->lifetime_points_redeemed - $points),
                LoyaltyTransaction::ADJUSTMENT => $points > 0 ? $membership->lifetime_points_earned += $points : null,
                default => null,
            };

            $membership->save();

            return LoyaltyTransaction::create(array_merge([
                'customer_id' => $customer->id,
                'type' => $type,
                'points' => $points,
                'balance_after' => $newBalance,
                'description' => $description,
                'performed_by' => Auth::id(),
            ], $attributes));
        });
    }

    /**
     * Award points for a completed order. Returns [awarded, reason, transaction].
     */
    public function awardForOrder(Order $order): array
    {
        $order->loadMissing('customer.membership.level');
        $customer = $order->customer;
        $reference = "order:{$order->id}:earn";

        $reason = match (true) {
            !$customer => 'Order is not associated with a customer.',
            $order->order_status !== config('shoplink.loyalty_earning_order_status') => 'Points are earned only when the order is ' . config('shoplink.loyalty_earning_order_status') . '.',
            !$this->isConfigured() => 'Loyalty earning is not configured or is disabled.',
            !$customer->membership?->loyalty_enrolled_at => 'Customer is not enrolled in the loyalty program.',
            !$customer->canTransact() => "Customer account status ({$customer->status}) is not eligible to earn points.",
            LoyaltyTransaction::where('reference', $reference)->exists() => 'Points for this order were already awarded.',
            default => null,
        };

        if ($reason) {
            return ['awarded' => false, 'reason' => $reason, 'transaction' => null];
        }

        $points = $this->calculatePoints((float) $order->total_amount, $customer->membership->level);
        if ($points <= 0) {
            return ['awarded' => false, 'reason' => 'Order total does not qualify for points under the current rules.', 'transaction' => null];
        }

        $transaction = $this->post($customer, $points, LoyaltyTransaction::EARN, "Points earned on {$order->transaction_number}", [
            'order_id' => $order->id,
            'reference' => $reference,
        ]);

        $this->notifier->send($customer, 'points_earned', 'You earned ShopLink loyalty points', "You earned {$points} points on transaction {$order->transaction_number}.\nNew balance: {$transaction->balance_after} points.");
        $this->evaluateUpgrade($customer);

        return ['awarded' => true, 'reason' => null, 'transaction' => $transaction];
    }

    /**
     * Reverse points earned on an order that was cancelled.
     */
    public function reverseForOrder(Order $order): ?LoyaltyTransaction
    {
        $earned = LoyaltyTransaction::where('reference', "order:{$order->id}:earn")->first();
        if (!$earned || LoyaltyTransaction::where('reference', "order:{$order->id}:reversal")->exists()) {
            return null;
        }

        $customer = $earned->customer;
        $balance = $this->membershipFor($customer)->points_balance;
        // Points already spent cannot be clawed back below zero; the shortfall is noted.
        $reverse = min($earned->points, $balance);
        $note = $reverse < $earned->points ? " ({$earned->points} earned; only {$reverse} available to reverse)" : '';

        return $this->post($customer, -$reverse, LoyaltyTransaction::REVERSAL, "Points reversed for cancelled {$order->transaction_number}{$note}", [
            'order_id' => $order->id,
            'reference' => "order:{$order->id}:reversal",
        ]);
    }

    public function adjust(Customer $customer, int $points, string $reason, User $user): LoyaltyTransaction
    {
        if ($points === 0) {
            throw new BusinessRuleException('Adjustment points cannot be zero.');
        }

        $membership = $this->membershipFor($customer);
        if (!$membership->loyalty_enrolled_at) {
            throw new BusinessRuleException('Customer is not enrolled in the loyalty program.');
        }

        $transaction = $this->post($customer, $points, LoyaltyTransaction::ADJUSTMENT, $points > 0 ? 'Manual points credit' : 'Manual points deduction', [
            'reason' => $reason,
            'authorized_by' => $user->id,
        ]);

        CustomerAccountLogger::log($customer, 'points_adjusted', "Points adjusted by {$points}.", [
            'points' => $points,
            'balance_after' => $transaction->balance_after,
            'reason' => $reason,
        ]);

        if ($points > 0) {
            $this->evaluateUpgrade($customer);
        }

        return $transaction;
    }

    // ------------------------------------------------------------------
    // Redemption
    // ------------------------------------------------------------------

    /**
     * Returns null when eligible, otherwise the reason the customer cannot redeem.
     */
    public function redemptionBlocker(Customer $customer, Reward $reward, int $quantity = 1): ?string
    {
        $membership = $this->membershipFor($customer)->fresh('level');
        $cost = $reward->points_required * $quantity;

        if (!$customer->canTransact()) {
            return "Customer account status ({$customer->status}) is not eligible for redemption.";
        }
        if (!$membership->loyalty_enrolled_at) {
            return 'Customer is not enrolled in the loyalty program.';
        }
        if (!$reward->isAvailable()) {
            return 'Reward is not currently available.';
        }
        if ($reward->stock_quantity !== null && $reward->stock_quantity < $quantity) {
            return "Only {$reward->stock_quantity} left in stock.";
        }
        if ($reward->minLevel && $membership->level->rank < $reward->minLevel->rank) {
            return "Requires {$reward->minLevel->name} membership or higher.";
        }
        if ($reward->max_per_customer !== null) {
            $already = RewardRedemption::where('customer_id', $customer->id)
                ->where('reward_id', $reward->id)
                ->where('status', RewardRedemption::COMPLETED)
                ->sum('quantity');
            if ($already + $quantity > $reward->max_per_customer) {
                return "Limit of {$reward->max_per_customer} per customer reached.";
            }
        }
        if ($membership->points_balance < $cost) {
            return "Insufficient points: requires {$cost}, available {$membership->points_balance}.";
        }

        return null;
    }

    /**
     * @return array{redemption: RewardRedemption, duplicate: bool}
     */
    public function redeem(Customer $customer, Reward $reward, int $quantity, User $user, array $options = []): array
    {
        $key = $options['idempotency_key'] ?? null;

        // A resubmitted request (same idempotency key) returns the original redemption.
        if ($key && ($existing = RewardRedemption::where('idempotency_key', $key)->first())) {
            if ($existing->customer_id !== $customer->id || $existing->reward_id !== $reward->id) {
                throw new BusinessRuleException('This redemption request key was already used for a different redemption.', 409);
            }

            return ['redemption' => $existing, 'duplicate' => true];
        }

        $redemption = DB::transaction(function () use ($customer, $reward, $quantity, $user, $options, $key) {

            // Lock the reward row so concurrent redemptions cannot oversell stock.
            $reward = Reward::whereKey($reward->id)->lockForUpdate()->firstOrFail();
            $this->membershipFor($customer);
            CustomerMembership::where('customer_id', $customer->id)->lockForUpdate()->first();

            if ($blocker = $this->redemptionBlocker($customer, $reward, $quantity)) {
                throw new BusinessRuleException($blocker);
            }

            $cost = $reward->points_required * $quantity;

            $redemption = RewardRedemption::create([
                'customer_id' => $customer->id,
                'reward_id' => $reward->id,
                'quantity' => $quantity,
                'points_used' => $cost,
                'status' => RewardRedemption::COMPLETED,
                'branch_id' => $options['branch_id'] ?? null,
                'processed_by' => $user->id,
                'idempotency_key' => $key,
                'notes' => $options['notes'] ?? null,
                'confirmed_at' => now(),
            ]);

            if ($reward->stock_quantity !== null) {
                $reward->decrement('stock_quantity', $quantity);
            }

            $this->post($customer, -$cost, LoyaltyTransaction::REDEEM, "Redeemed {$quantity} x {$reward->name} ({$redemption->redemption_number})", [
                'reward_redemption_id' => $redemption->id,
                'reference' => "redemption:{$redemption->id}:redeem",
            ]);

            return $redemption;
        });

        $balance = $this->membershipFor($customer)->points_balance;
        $this->notifier->send($customer, 'points_redeemed', 'Reward redemption confirmation', "Redemption {$redemption->redemption_number}: {$quantity} x {$reward->name}.\nPoints used: {$redemption->points_used}. Remaining balance: {$balance} points.");

        return ['redemption' => $redemption, 'duplicate' => false];
    }

    public function cancelRedemption(RewardRedemption $redemption, string $reason, User $user): RewardRedemption
    {
        return DB::transaction(function () use ($redemption, $reason, $user) {
            $redemption = RewardRedemption::whereKey($redemption->id)->lockForUpdate()->firstOrFail();

            if ($redemption->status !== RewardRedemption::COMPLETED) {
                throw new BusinessRuleException('Only completed redemptions can be cancelled.');
            }

            $redemption->update([
                'status' => RewardRedemption::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $reason,
            ]);

            $reward = Reward::withTrashed()->whereKey($redemption->reward_id)->lockForUpdate()->first();
            if ($reward && $reward->stock_quantity !== null) {
                $reward->increment('stock_quantity', $redemption->quantity);
            }

            $this->post($redemption->customer, $redemption->points_used, LoyaltyTransaction::REFUND, "Points refunded for cancelled {$redemption->redemption_number}", [
                'reward_redemption_id' => $redemption->id,
                'reference' => "redemption:{$redemption->id}:refund",
                'reason' => $reason,
                'authorized_by' => $user->id,
            ]);

            return $redemption->fresh(['reward', 'customer']);
        });
    }

    // ------------------------------------------------------------------
    // Expiry
    // ------------------------------------------------------------------

    /**
     * Inactivity-based expiry: when points_expiry_months is configured, the
     * balance of members with no points activity for that many months expires.
     */
    public function expireInactiveBalances(): int
    {
        $months = $this->settings()['points_expiry_months'];
        if (!$months) {
            return 0;
        }

        $cutoff = now()->subMonths($months);
        $count = 0;

        CustomerMembership::with('customer')
            ->where('points_balance', '>', 0)
            ->where(function ($q) use ($cutoff) {
                $q->where('last_points_activity_at', '<', $cutoff)
                    ->orWhere(fn ($q) => $q->whereNull('last_points_activity_at')->where('loyalty_enrolled_at', '<', $cutoff));
            })
            ->each(function (CustomerMembership $membership) use ($months, &$count) {
                $this->post($membership->customer, -$membership->points_balance, LoyaltyTransaction::EXPIRY, "Points expired after {$months} months without activity", [
                    'reference' => 'expiry:' . $membership->customer_id . ':' . now()->format('YmdHis'),
                ]);
                $count++;
            });

        return $count;
    }
}
