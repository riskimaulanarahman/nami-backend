<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use App\Enums\BillingMode;
use App\Enums\OpenBillStatus;
use App\Enums\SessionType;
use App\Enums\TableType;
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
        'origin_staff_name', 'source_table_id', 'source_table_name',
        'source_table_type', 'session_type', 'billing_mode',
        'session_started_at', 'session_ended_at', 'duration_minutes',
        'session_charge_name', 'session_charge_total',
        'selected_package_name', 'selected_package_hours',
        'selected_package_price', 'locked_final',
    ];

    protected function casts(): array
    {
        return [
            'status' => OpenBillStatus::class,
            'points_to_redeem' => 'integer',
            'source_table_type' => TableType::class,
            'session_type' => SessionType::class,
            'billing_mode' => BillingMode::class,
            'session_started_at' => 'datetime',
            'session_ended_at' => 'datetime',
            'duration_minutes' => 'integer',
            'session_charge_total' => 'integer',
            'selected_package_hours' => 'integer',
            'selected_package_price' => 'integer',
            'locked_final' => 'boolean',
        ];
    }

    public function member() { return $this->belongsTo(Member::class); }
    public function groups() { return $this->hasMany(OpenBillGroup::class); }
    public function involvedStaff() { return $this->hasMany(OpenBillInvolvedStaff::class); }

    public function isFrozenTableDraft(): bool
    {
        return $this->locked_final === true
            && $this->status === OpenBillStatus::Draft;
    }
}
