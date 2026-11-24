<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationSyncCursor extends Model
{
    protected $fillable = ['resource', 'last_updated_at', 'last_run_at'];

    protected $casts = [
        'last_run_at' => 'datetime',
    ];
}
