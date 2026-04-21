<?php

namespace App\Models;

use App\Enums\FulfillmentType;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class OpenBillGroup extends Model
{
    use TenantScoped;
    use HasUlids;

    protected $fillable = ['tenant_id', 'open_bill_id', 'fulfillment_type', 'table_id', 'table_name', 'subtotal'];

    protected function casts(): array
    {
        return [
            'fulfillment_type' => FulfillmentType::class,
            'subtotal' => 'integer',
        ];
    }

    public function openBill() { return $this->belongsTo(OpenBill::class); }
    public function items() { return $this->hasMany(OpenBillGroupItem::class); }
    public function table() { return $this->belongsTo(Table::class); }

    public function recalculateSubtotal(): void
    {
        $this->update([
            'subtotal' => $this->items()->sum(\DB::raw('unit_price * quantity')),
        ]);
    }
}
