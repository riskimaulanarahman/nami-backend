<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class TableOrderItem extends Model
{
    use TenantScoped;

    protected $fillable = ['tenant_id', 'table_id', 'menu_item_id', 'quantity', 'unit_price', 'added_at'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'added_at' => 'datetime',
        ];
    }

    public function table() { return $this->belongsTo(Table::class); }
    public function menuItem() { return $this->belongsTo(MenuItem::class); }
}
