<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use App\Enums\BillingMode;
use App\Enums\OpenBillCloseReason;
use App\Enums\OpenBillStatus;
use App\Enums\SessionType;
use App\Enums\TableType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class OpenBill extends Model
{
    use SoftDeletes;
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
        'selected_package_price', 'locked_final', 'close_reason',
        'delete_reason', 'deleted_by_staff_id', 'deleted_by_staff_name',
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
            'close_reason' => OpenBillCloseReason::class,
            'deleted_at' => 'datetime',
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

    public function draftTotalAmount(): int
    {
        $orderTotal = $this->groups->sum(function (OpenBillGroup $group): int {
            $itemSubtotal = $group->items->sum(fn ($item) => $item->unit_price * $item->quantity);

            return $group->subtotal > 0 ? (int) $group->subtotal : (int) $itemSubtotal;
        });

        return $orderTotal + (int) ($this->session_charge_total ?? 0);
    }

    public function reportSessionType(): string
    {
        return $this->session_type?->value ?? 'cafe';
    }

    public function reportTableName(): string
    {
        if (!empty($this->source_table_name)) {
            return $this->source_table_name;
        }

        $groupTableName = $this->groups
            ->pluck('table_name')
            ->filter()
            ->first();

        return $groupTableName ?: ($this->customer_name ?: $this->code);
    }
}
