<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTransaction extends Model
{
    public const EARN = 'earn';
    public const REDEEM = 'redeem';
    public const ADJUSTMENT = 'adjustment';
    public const REVERSAL = 'reversal';
    public const REFUND = 'refund';
    public const EXPIRY = 'expiry';

    protected $fillable = [
        'customer_id',
        'type',
        'points',
        'balance_after',
        'order_id',
        'reward_redemption_id',
        'reference',
        'description',
        'reason',
        'performed_by',
        'authorized_by'
    ];

    protected $casts = [
        'points' => 'integer',
        'balance_after' => 'integer'
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function redemption(): BelongsTo
    {
        return $this->belongsTo(RewardRedemption::class, 'reward_redemption_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
