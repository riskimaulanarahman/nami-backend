<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderGroupItem extends Model
{
    use TenantScoped;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'order_group_id', 'menu_item_id', 'menu_item_name', 'menu_item_emoji',
        'unit_price', 'unit_cost', 'quantity', 'subtotal', 'note',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'unit_cost' => 'integer',
            'quantity' => 'integer',
            'subtotal' => 'integer',
        ];
    }

    public function group(): BelongsTo { return $this->belongsTo(OrderGroup::class, 'order_group_id'); }
    public function menuItem(): BelongsTo { return $this->belongsTo(MenuItem::class); }
}
