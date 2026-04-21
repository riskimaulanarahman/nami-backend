<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashierShiftExpense extends Model
{
    use TenantScoped, HasUlids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'cashier_shift_id',
        'staff_id',
        'staff_name',
        'amount',
        'description',
        'category',
        'delete_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function shift()
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
}
