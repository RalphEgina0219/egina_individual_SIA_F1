<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reward extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'points_required',
        'stock_quantity',
        'max_per_customer',
        'min_membership_level_id',
        'is_active',
        'valid_from',
        'valid_until',
        'created_by'
    ];

    protected $casts = [
        'points_required' => 'integer',
        'stock_quantity' => 'integer',
        'max_per_customer' => 'integer',
        'is_active' => 'boolean',
        'valid_from' => 'date:Y-m-d',
        'valid_until' => 'date:Y-m-d'
    ];

    public function minLevel(): BelongsTo
    {
        return $this->belongsTo(MembershipLevel::class, 'min_membership_level_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(RewardRedemption::class);
    }

    /**
     * Active, within its validity window, and in stock.
     */
    public function isAvailable(): bool
    {
        $today = now()->startOfDay();

        return $this->is_active
            && ($this->valid_from === null || $this->valid_from->lte($today))
            && ($this->valid_until === null || $this->valid_until->gte($today))
            && ($this->stock_quantity === null || $this->stock_quantity > 0);
    }
}
