<?php

namespace App\Models;

use App\Enums\FulfillmentType;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class OrderGroup extends Model
{
    use TenantScoped;
    use HasUlids;

    protected $fillable = ['tenant_id', 'order_id', 'fulfillment_type', 'table_id', 'table_name', 'subtotal'];

    protected function casts(): array
    {
        return ['fulfillment_type' => FulfillmentType::class, 'subtotal' => 'integer'];
    }

    public function order() { return $this->belongsTo(Order::class); }
    public function items() { return $this->hasMany(OrderGroupItem::class); }
}
