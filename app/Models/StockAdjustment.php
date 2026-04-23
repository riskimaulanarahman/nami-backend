<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

use App\Enums\StockAdjustmentType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;


class StockAdjustment extends Model
{
    use TenantScoped;
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'ingredient_id', 'type', 'quantity', 'reason', 'adjusted_by',
        'previous_stock', 'new_stock',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockAdjustmentType::class,
            'quantity' => 'float',
            'previous_stock' => 'float',
            'new_stock' => 'float',
        ];
    }

    public function ingredient() { return $this->belongsTo(Ingredient::class)->withTrashed(); }
}
