<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAccountLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'user_id',
        'action',
        'changes',
        'description',
        'identity_verified',
        'verification_method',
        'request_channel',
        'ip_address'
    ];

    protected $casts = [
        'changes' => 'array',
        'identity_verified' => 'boolean',
        'created_at' => 'datetime'
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
