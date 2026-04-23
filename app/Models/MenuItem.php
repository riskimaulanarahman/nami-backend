<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

class MenuItem extends Model
{
    use TenantScoped;
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name', 'legacy_category', 'category_id', 'price', 'cost',
        'description', 'is_available', 'sort_order',
    ];
    protected $hidden = ['emoji'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'cost' => 'integer',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category() { return $this->belongsTo(MenuCategory::class, 'category_id'); }

    public function recipes() { return $this->hasMany(MenuItemRecipe::class); }

    public function effectiveCost(): int
    {
        $this->loadMissing('recipes.ingredient');

        return (int) $this->recipes->sum(
            fn (MenuItemRecipe $recipe) => (int) round($recipe->quantity * (int) ($recipe->ingredient?->unit_cost ?? 0))
        );
    }

    public function ingredients()
    {
        return $this->belongsToMany(Ingredient::class, 'menu_item_recipes')
            ->withPivot('quantity');
    }
}
