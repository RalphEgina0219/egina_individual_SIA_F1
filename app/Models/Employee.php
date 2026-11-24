<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kopya (read-only) ng employees ng HR. Ina-update lang ng
 * MasterDataSeeder at ng integration:sync-masterdata.
 */
class Employee extends Model
{
    protected $fillable = [
        'employee_no',
        'first_name',
        'last_name',
        'email',
        'branch_code',
        'department_code',
        'position',
        'employment_status',
        'can_login',
        'crm_role',
        'source_updated_at',
    ];

    protected $casts = [
        'can_login' => 'boolean',
        'source_updated_at' => 'datetime',
    ];

    protected $appends = ['full_name'];

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * B7: true lang kapag Active o On Leave.
     */
    public static function canLoginFor(?string $status): bool
    {
        return in_array($status, ['Active', 'On Leave'], true);
    }
}
