<?php

namespace App\Services;

use App\Enums\CashierShiftStatus;
use App\Enums\PaymentMethodType;
use App\Models\CashierShift;
use App\Models\CashierShiftInvolvedStaff;
use App\Models\Staff;
use App\Events\ShiftOpened;
use App\Events\ShiftClosed;

class CashierShiftService
{
    public function getActiveShift(): ?CashierShift
    {
        return CashierShift::where('status', CashierShiftStatus::Active)->first();
    }

    public function openShift(Staff $staff, int $openingCash, ?string $note = null): CashierShift
    {
        $existing = $this->getActiveShift();
        if ($existing) {
            throw new \RuntimeException('Masih ada shift aktif. Tutup shift terlebih dahulu.');
        }

        $shift = CashierShift::create([
            'staff_id' => $staff->id,
            'staff_name' => $staff->name,
            'status' => CashierShiftStatus::Active,
            'opened_at' => now(),
            'opening_cash' => $openingCash,
            'expected_cash' => $openingCash,
            'note' => $note,
        ]);

        CashierShiftInvolvedStaff::create([
            'cashier_shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'staff_name' => $staff->name,
        ]);

        event(new ShiftOpened($shift));

        return $shift;
    }

    public function closeShift(CashierShift $shift, int $closingCash, ?string $note = null): CashierShift
    {
        if ($shift->status !== CashierShiftStatus::Active) {
            throw new \RuntimeException('Shift ini sudah ditutup.');
        }

        $totalExpenses = $shift->expenses()->sum('amount');
        $expectedCash  = $shift->opening_cash + $shift->cash_sales - $shift->cash_refunds - $totalExpenses;

        $shift->update([
            'status'         => CashierShiftStatus::Closed,
            'closed_at'      => now(),
            'closing_cash'   => $closingCash,
            'expected_cash'  => $expectedCash,
            'variance_cash'  => $closingCash - $expectedCash,
            'total_expenses' => $totalExpenses,
            'note'           => $note ?? $shift->note,
        ]);

        event(new ShiftClosed($shift));

        return $shift->fresh();
    }

    public function recordTransaction(
        CashierShift $shift,
        int $amount,
        PaymentMethodType $paymentType,
        array $staffIds = [],
        array $staffNames = [],
    ): void {
        $updates = ['transaction_count' => $shift->transaction_count + 1];

        if ($paymentType === PaymentMethodType::Cash) {
            $updates['cash_sales'] = $shift->cash_sales + $amount;
        } else {
            $updates['non_cash_sales'] = $shift->non_cash_sales + $amount;
        }
        $updates['expected_cash'] = ($shift->opening_cash + ($updates['cash_sales'] ?? $shift->cash_sales) - $shift->cash_refunds - $shift->total_expenses);

        $shift->update($updates);
        $this->mergeInvolvedStaff($shift, $staffIds, $staffNames);
    }

    public function recordRefund(
        CashierShift $shift,
        int $amount,
        PaymentMethodType $paymentType,
        array $staffIds = [],
        array $staffNames = [],
    ): void {
        $updates = ['refund_count' => $shift->refund_count + 1];

        if ($paymentType === PaymentMethodType::Cash) {
            $updates['cash_refunds'] = $shift->cash_refunds + $amount;
        } else {
            $updates['non_cash_refunds'] = $shift->non_cash_refunds + $amount;
        }
        $updates['expected_cash'] = ($shift->opening_cash + $shift->cash_sales - ($updates['cash_refunds'] ?? $shift->cash_refunds) - $shift->total_expenses);

        $shift->update($updates);
        $this->mergeInvolvedStaff($shift, $staffIds, $staffNames);
    }

    private function mergeInvolvedStaff(CashierShift $shift, array $staffIds, array $staffNames): void
    {
        foreach ($staffIds as $i => $staffId) {
            CashierShiftInvolvedStaff::firstOrCreate(
                ['cashier_shift_id' => $shift->id, 'staff_id' => $staffId],
                ['staff_name' => $staffNames[$i] ?? 'Unknown']
            );
        }
    }
}
