<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

use App\Enums\IngredientUnit;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

class Ingredient extends Model
{
    use TenantScoped;
    use HasUlids, SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'unit', 'stock', 'min_stock', 'unit_cost', 'last_restocked_at'];

    protected function casts(): array
    {
        return [
            'unit' => IngredientUnit::class,
            'stock' => 'float',
            'min_stock' => 'float',
            'unit_cost' => 'integer',
            'last_restocked_at' => 'datetime',
        ];
    }

    public function isLowStock(): bool { return $this->stock <= $this->min_stock; }

    public function recipes() { return $this->hasMany(MenuItemRecipe::class); }
    public function adjustments() { return $this->hasMany(StockAdjustment::class); }
}
