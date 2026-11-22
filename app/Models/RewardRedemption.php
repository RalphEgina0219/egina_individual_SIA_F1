<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardRedemption extends Model
{
    public const COMPLETED = 'Completed';
    public const CANCELLED = 'Cancelled';

    protected $fillable = [
        'customer_id',
        'reward_id',
        'quantity',
        'points_used',
        'status',
        'branch_id',
        'processed_by',
        'idempotency_key',
        'notes',
        'confirmed_at',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason'
    ];

    protected $casts = [
        'quantity' => 'integer',
        'points_used' => 'integer',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime'
    ];

    protected static function boot()
    {
        parent::boot();

        static::created(function ($redemption) {
            $redemption->redemption_number = sprintf('RDM-%s-%06d', now()->format('Ymd'), $redemption->id);
            $redemption->saveQuietly();
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
