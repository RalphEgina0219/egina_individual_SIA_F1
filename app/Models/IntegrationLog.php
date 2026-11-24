<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationLog extends Model
{
    // created_at lang ang mayroon ang table na ito.
    public const UPDATED_AT = null;

    protected $fillable = [
        'direction',
        'system',
        'method',
        'url',
        'reference_number',
        'request_body',
        'status_code',
        'response_body',
        'duration_ms',
        'error',
    ];

    protected $casts = [
        'request_body' => 'array',
        'response_body' => 'array',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];
}
