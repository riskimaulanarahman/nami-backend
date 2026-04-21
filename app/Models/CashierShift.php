<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

use App\Enums\CashierShiftStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;


class CashierShift extends Model
{
    use TenantScoped;
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'staff_id', 'staff_name', 'status', 'opened_at', 'closed_at',
        'opening_cash', 'closing_cash', 'expected_cash', 'variance_cash',
        'cash_sales', 'cash_refunds', 'non_cash_sales', 'non_cash_refunds',
        'total_expenses', 'transaction_count', 'refund_count', 'note', 'is_legacy',
    ];

    protected function casts(): array
    {
        return [
            'status' => CashierShiftStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'integer',
            'closing_cash' => 'integer',
            'expected_cash' => 'integer',
            'variance_cash' => 'integer',
            'cash_sales' => 'integer',
            'cash_refunds' => 'integer',
            'non_cash_sales' => 'integer',
            'non_cash_refunds' => 'integer',
            'total_expenses' => 'integer',
            'transaction_count' => 'integer',
            'refund_count' => 'integer',
            'is_legacy' => 'boolean',
        ];
    }

    public function tenant() { return $this->belongsTo(Tenant::class); }

    public function staff() { return $this->belongsTo(Staff::class); }

    public function involvedStaff() { return $this->hasMany(CashierShiftInvolvedStaff::class); }

    public function orders() { return $this->hasMany(Order::class, 'cashier_shift_id'); }

    public function expenses() { return $this->hasMany(CashierShiftExpense::class); }

    public function computeExpectedCash(): int
    {
        $totalExpenses = $this->expenses()->sum('amount');
        return $this->opening_cash + $this->cash_sales - $this->cash_refunds - $totalExpenses;
    }
}
