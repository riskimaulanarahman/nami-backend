<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class MenuItemRecipe extends Model
{
    use TenantScoped;

    public $timestamps = false;
    protected $fillable = ['tenant_id', 'menu_item_id', 'ingredient_id', 'quantity'];
    protected function casts(): array { return ['quantity' => 'float']; }
    public function menuItem() { return $this->belongsTo(MenuItem::class); }
    public function ingredient() { return $this->belongsTo(Ingredient::class); }
}
