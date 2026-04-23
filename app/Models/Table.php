<?php

namespace App\Models;

use App\Enums\BillingMode;
use App\Enums\SessionType;
use App\Enums\TableStatus;
use App\Enums\TableType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\TenantScoped;

class Table extends Model
{
    use TenantScoped, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name', 'type', 'status', 'hourly_rate', 'start_time', 'session_type',
        'billing_mode', 'active_open_bill_id', 'selected_package_id',
        'selected_package_name', 'selected_package_hours', 'selected_package_price',
        'package_minutes_total', 'package_total_price',
        'package_expired_at', 'overrun_started_at', 'accrued_overrun_cost',
        'package_reminder_shown_at', 'origin_cashier_shift_id', 'origin_staff_id',
        'origin_staff_name',
    ];

    protected function casts(): array
    {
        return [
            'type' => TableType::class,
            'status' => TableStatus::class,
            'session_type' => SessionType::class,
            'billing_mode' => BillingMode::class,
            'hourly_rate' => 'integer',
            'selected_package_hours' => 'integer',
            'selected_package_price' => 'integer',
            'package_minutes_total' => 'integer',
            'package_total_price' => 'integer',
            'package_expired_at' => 'datetime',
            'overrun_started_at' => 'datetime',
            'accrued_overrun_cost' => 'integer',
            'start_time' => 'datetime',
            'package_reminder_shown_at' => 'datetime',
        ];
    }

    public function layoutPosition()
    {
        return $this->hasOne(TableLayoutPosition::class);
    }

    public function involvedStaff()
    {
        return $this->hasMany(TableInvolvedStaff::class);
    }

    public function orderItems()
    {
        return $this->hasMany(TableOrderItem::class);
    }

    public function activeOpenBill()
    {
        return $this->belongsTo(OpenBill::class, 'active_open_bill_id');
    }

    public function resetSession(): void
    {
        $this->update([
            'status' => TableStatus::Available,
            'start_time' => null,
            'session_type' => null,
            'billing_mode' => null,
            'active_open_bill_id' => null,
            'selected_package_id' => null,
            'selected_package_name' => null,
            'selected_package_hours' => 0,
            'selected_package_price' => 0,
            'package_minutes_total' => 0,
            'package_total_price' => 0,
            'package_expired_at' => null,
            'overrun_started_at' => null,
            'accrued_overrun_cost' => 0,
            'package_reminder_shown_at' => null,
            'origin_cashier_shift_id' => null,
            'origin_staff_id' => null,
            'origin_staff_name' => null,
        ]);
        $this->involvedStaff()->delete();
        $this->orderItems()->delete();
    }
}
