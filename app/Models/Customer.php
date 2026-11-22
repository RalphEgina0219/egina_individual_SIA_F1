<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'full_name',
        'phone',
        'email',
        'address',
        'registration_date',
        'birthday',
        'preferred_branch_id',
        'preferred_contact_method',
        'customer_type',
        'status',
        'status_reason',
        'status_changed_at',
        'registration_channel',
        'registered_by',
        'verified_at',
        'verified_by',
        'verification_method',
        'activated_at',
        'notes',
    ];

    protected $attributes = [
        'registration_date' => null, // Set in the creating hook
        'status' => 'Pending Verification',
        'preferred_contact_method' => 'SMS',
        'customer_type' => 'Individual',
        'registration_channel' => 'In-store',
    ];

    protected $casts = [
        'registration_date' => 'date',
        'birthday' => 'date:Y-m-d',
        'verified_at' => 'datetime',
        'activated_at' => 'datetime',
        'status_changed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($customer) {
            if (empty($customer->registration_date)) {
                $customer->registration_date = now()->toDateString();
            }
        });

        // Customer ID and Membership Number are derived from the primary key,
        // which guarantees uniqueness without a separate sequence table.
        static::created(function ($customer) {
            $customer->customer_code = sprintf('CUST-%06d', $customer->id);
            $customer->membership_number = sprintf('SLM-%s-%06d', now()->format('Y'), $customer->id);
            $customer->saveQuietly();
        });
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function membership(): HasOne
    {
        return $this->hasOne(CustomerMembership::class);
    }

    public function preferredBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'preferred_branch_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(RewardRedemption::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(CustomerFeedback::class);
    }

    public function accountLogs(): HasMany
    {
        return $this->hasMany(CustomerAccountLog::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(CustomerNotification::class);
    }

    /**
     * Whether the account may transact, earn and redeem points.
     */
    public function canTransact(): bool
    {
        return in_array($this->status, config('shoplink.transacting_statuses'), true);
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (!$search) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('full_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('customer_code', 'like', "%{$search}%")
                ->orWhere('membership_number', 'like', "%{$search}%");
        });
    }
}
