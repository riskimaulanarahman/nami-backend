<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

use App\Enums\BillType;
use App\Enums\BillingMode;
use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethodType;
use App\Enums\SessionType;
use App\Enums\TableType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;


class Order extends Model
{
    use TenantScoped;
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'table_id', 'table_name', 'table_type', 'session_type', 'bill_type',
        'billiard_billing_mode', 'dining_type', 'start_time', 'end_time',
        'duration_minutes', 'session_duration_hours', 'rental_cost',
        'selected_package_id', 'selected_package_name', 'selected_package_hours',
        'selected_package_price', 'order_total', 'grand_total', 'order_cost',
        'served_by', 'status', 'refunded_at', 'refunded_by', 'refund_reason',
        'refund_authorization_method', 'refund_authorized_by',
        'refund_authorized_role', 'refund_owner_email',
        'payment_method_id', 'payment_method_name', 'payment_method_type',
        'payment_reference', 'cashier_shift_id', 'refunded_in_cashier_shift_id',
        'origin_cashier_shift_id', 'origin_staff_id', 'origin_staff_name',
        'is_continued_from_previous_shift', 'member_id', 'member_code',
        'member_name', 'points_earned', 'points_redeemed', 'redeem_amount',
    ];

    protected function casts(): array
    {
        return [
            'table_type' => TableType::class,
            'session_type' => SessionType::class,
            'bill_type' => BillType::class,
            'billiard_billing_mode' => BillingMode::class,
            'dining_type' => FulfillmentType::class,
            'status' => OrderStatus::class,
            'payment_method_type' => PaymentMethodType::class,
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'refunded_at' => 'datetime',
            'duration_minutes' => 'integer',
            'session_duration_hours' => 'integer',
            'rental_cost' => 'integer',
            'selected_package_hours' => 'integer',
            'selected_package_price' => 'integer',
            'order_total' => 'integer',
            'grand_total' => 'integer',
            'order_cost' => 'integer',
            'points_earned' => 'integer',
            'points_redeemed' => 'integer',
            'redeem_amount' => 'integer',
            'is_continued_from_previous_shift' => 'boolean',
        ];
    }

    public function table() { return $this->belongsTo(Table::class); }
    public function cashierShift() { return $this->belongsTo(CashierShift::class); }
    public function groups() { return $this->hasMany(OrderGroup::class); }
    public function involvedStaff() { return $this->hasMany(OrderInvolvedStaff::class); }
}
