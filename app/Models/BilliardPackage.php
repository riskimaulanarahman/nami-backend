<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

class BilliardPackage extends Model
{
    use TenantScoped;
    use HasUlids, SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'duration_hours', 'price', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'duration_hours' => 'integer',
            'price' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
