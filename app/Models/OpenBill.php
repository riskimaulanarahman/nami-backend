<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

use App\Enums\OpenBillStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;


class OpenBill extends Model
{
    use TenantScoped;
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'code', 'customer_name', 'member_id', 'points_to_redeem', 'status',
        'waiting_list_entry_id', 'origin_cashier_shift_id', 'origin_staff_id',
        'origin_staff_name',
    ];

    protected function casts(): array
    {
        return [
            'status' => OpenBillStatus::class,
            'points_to_redeem' => 'integer',
        ];
    }

    public function member() { return $this->belongsTo(Member::class); }
    public function groups() { return $this->hasMany(OpenBillGroup::class); }
    public function involvedStaff() { return $this->hasMany(OpenBillInvolvedStaff::class); }
}
