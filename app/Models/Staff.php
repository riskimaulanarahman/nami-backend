<?php

namespace App\Models;

use App\Enums\StaffRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Concerns\TenantScoped;

class Staff extends Authenticatable
{
    use TenantScoped, HasApiTokens, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'staff';

    protected $fillable = [
        'tenant_id', 'name', 'username', 'pin', 'role', 'avatar', 'is_active',
    ];

    protected $hidden = ['pin'];

    protected function casts(): array
    {
        return [
            'role' => StaffRole::class,
            'is_active' => 'boolean',
            'pin' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === StaffRole::Admin;
    }

    public function cashierShifts()
    {
        return $this->hasMany(CashierShift::class, 'staff_id');
    }
}
