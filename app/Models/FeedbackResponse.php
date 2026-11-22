<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackResponse extends Model
{
    public const TYPES = [
        'Internal Note',
        'Investigation',
        'Resolution',
        'Customer Communication',
        'Status Update',
    ];

    protected $fillable = [
        'customer_feedback_id',
        'user_id',
        'response_type',
        'message',
        'status_from',
        'status_to',
        'communicated_via'
    ];

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(CustomerFeedback::class, 'customer_feedback_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
