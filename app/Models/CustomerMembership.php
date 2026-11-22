<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMembership extends Model
{
    protected $fillable = [
        'customer_id',
        'membership_level_id',
        'loyalty_enrolled_at',
        'enrolled_by',
        'points_balance',
        'lifetime_points_earned',
        'lifetime_points_redeemed',
        'last_points_activity_at',
        'level_assigned_at',
        'level_locked'
    ];

    protected $casts = [
        'loyalty_enrolled_at' => 'datetime',
        'last_points_activity_at' => 'datetime',
        'level_assigned_at' => 'datetime',
        'points_balance' => 'integer',
        'lifetime_points_earned' => 'integer',
        'lifetime_points_redeemed' => 'integer',
        'level_locked' => 'boolean'
    ];

    protected $appends = ['is_loyalty_member'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(MembershipLevel::class, 'membership_level_id');
    }

    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }

    public function getIsLoyaltyMemberAttribute(): bool
    {
        return $this->loyalty_enrolled_at !== null;
    }
}
