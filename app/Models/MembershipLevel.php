<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipLevel extends Model
{
    public const REGULAR = 'REGULAR';
    public const REGISTERED = 'REGISTERED';
    public const LOYALTY = 'LOYALTY';
    public const GOLD = 'GOLD';
    public const VIP = 'VIP';

    protected $fillable = [
        'name',
        'code',
        'rank',
        'min_lifetime_points',
        'points_multiplier',
        'description',
        'benefits',
        'is_active'
    ];

    protected $casts = [
        'rank' => 'integer',
        'min_lifetime_points' => 'integer',
        'points_multiplier' => 'decimal:2',
        'is_active' => 'boolean'
    ];

    public static function byCode(string $code): self
    {
        return static::where('code', $code)->firstOrFail();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CustomerMembership::class);
    }
}
