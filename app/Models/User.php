<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
  use HasApiTokens, HasFactory, Notifiable;

  protected $fillable = [
    'name',
    'email',
    'password',
    'role',
    'phone',
    'is_active',
    'employee_no',
    'employment_status',
    'can_login',
    'branch_code',
  ];

  protected $hidden = [
    'password',
    'remember_token',
  ];

  protected $casts = [
    'email_verified_at' => 'datetime',
    'password' => 'hashed',
    'is_active' => 'boolean',
    'can_login' => 'boolean',
  ];

  protected $attributes = [
    'is_active' => true,
    'can_login' => true,
  ];

  public function orders(): HasMany
  {
    return $this->hasMany(Order::class);
  }

  /**
   * Check if user has specific role
   */
  public function hasRole($role): bool
  {
    return $this->role === $role;
  }

  /**
   * Check if user has any of the given roles
   */
  public function hasAnyRole(array $roles): bool
  {
    return in_array($this->role, $roles);
  }

  /**
   * Check a Customer Management / module permission (see App\Support\Permissions)
   */
  public function can($abilities, $arguments = []): bool
  {
    if (is_string($abilities) && array_key_exists($abilities, Permissions::MAP)) {
      return $this->is_active && Permissions::allows($this->role, $abilities);
    }

    return parent::can($abilities, $arguments);
  }

  /**
   * @return string[]
   */
  public function permissions(): array
  {
    return $this->is_active ? Permissions::forRole($this->role) : [];
  }
}
