<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationOutbox extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $table = 'integration_outbox';

    protected $fillable = [
        'target_system',
        'method',
        'endpoint',
        'payload',
        'reference_number',
        'status',
        'attempts',
        'last_error',
        'next_attempt_at',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
