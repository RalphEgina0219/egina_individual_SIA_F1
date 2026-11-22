<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerFeedback extends Model
{
    protected $table = 'customer_feedback';

    protected $fillable = [
        'customer_id',
        'contact_name',
        'contact_info',
        'type',
        'category',
        'channel',
        'rating',
        'subject',
        'message',
        'branch_id',
        'order_id',
        'product_id',
        'priority',
        'status',
        'department',
        'assigned_to',
        'recorded_by',
        'received_at',
        'resolution_summary',
        'resolved_at',
        'closed_at',
        'customer_notified_at'
    ];

    protected $attributes = [
        'status' => 'Pending',
        'priority' => 'Medium',
    ];

    protected $casts = [
        'rating' => 'integer',
        'received_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'customer_notified_at' => 'datetime'
    ];

    protected $appends = ['is_complaint'];

    protected static function boot()
    {
        parent::boot();

        static::created(function ($feedback) {
            $feedback->reference_number = sprintf('FB-%s-%06d', now()->format('Y'), $feedback->id);
            $feedback->saveQuietly();
        });
    }

    public function getIsComplaintAttribute(): bool
    {
        return in_array($this->type, config('shoplink.complaint_types'), true);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ComplaintAssignment::class)->latest('assigned_at');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(FeedbackResponse::class)->latest();
    }
}
