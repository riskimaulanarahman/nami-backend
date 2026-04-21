<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

class MenuCategory extends Model
{
    use TenantScoped;
    use HasUlids, SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'emoji', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function menuItems() { return $this->hasMany(MenuItem::class, 'category_id'); }
}
