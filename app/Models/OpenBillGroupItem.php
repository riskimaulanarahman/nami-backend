<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class OpenBillGroupItem extends Model
{
    use TenantScoped;

    protected $fillable = ['tenant_id', 'open_bill_group_id', 'menu_item_id', 'quantity', 'unit_price', 'added_at', 'note'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'integer', 'added_at' => 'datetime'];
    }

    public function group() { return $this->belongsTo(OpenBillGroup::class, 'open_bill_group_id'); }
    public function menuItem() { return $this->belongsTo(MenuItem::class); }
}
