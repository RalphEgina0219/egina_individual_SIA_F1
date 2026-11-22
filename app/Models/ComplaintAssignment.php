<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplaintAssignment extends Model
{
    protected $fillable = [
        'customer_feedback_id',
        'department',
        'assigned_to',
        'assigned_by',
        'notes',
        'is_current',
        'assigned_at'
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'assigned_at' => 'datetime'
    ];

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(CustomerFeedback::class, 'customer_feedback_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
