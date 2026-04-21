<?php

namespace App\Services;

use App\Models\Table;
use Illuminate\Support\Carbon;

class BillingService
{
    private function elapsedSecondsSince(?Carbon $startTime): int
    {
        if (!$startTime) return 0;

        return max(0, now()->getTimestamp() - $startTime->getTimestamp());
    }

    /**
     * Calculate billiard rental cost.
     */
    public function calculateRentalCost(Table $table): int
    {
        if ($table->billing_mode?->value === 'package') {
            return $table->selected_package_price;
        }

        // Open-bill: ceil(duration in hours) × hourly_rate
        if (!$table->start_time) return 0;

        $durationMinutes = intval(ceil($this->elapsedSecondsSince($table->start_time) / 60));
        return intval(ceil($durationMinutes / 60)) * $table->hourly_rate;
    }

    /**
     * Calculate duration in minutes for a table session.
     */
    public function calculateDurationMinutes(Table $table): int
    {
        if (!$table->start_time) return 0;
        return intval(floor($this->elapsedSecondsSince($table->start_time) / 60));
    }

    /**
     * Calculate order total (F&B items) for a table.
     */
    public function calculateOrderTotal(Table $table): int
    {
        $total = $table->orderItems->sum(fn ($item) => $item->unit_price * $item->quantity);

        if ($table->active_open_bill_id) {
            $openBill = \App\Models\OpenBill::with('groups.items')->find($table->active_open_bill_id);
            if ($openBill) {
                foreach ($openBill->groups as $group) {
                    if ($group->fulfillment_type->value === 'dine-in' && $group->table_id === $table->id) {
                        $total += $group->items->sum(fn ($i) => $i->unit_price * $i->quantity);
                    }
                }
            }
        }

        return $total;
    }

    /**
     * Calculate order cost (HPP) for a table.
     */
    public function calculateOrderCost(Table $table): int
    {
        $table->loadMissing('orderItems.menuItem');
        $cost = $table->orderItems->sum(fn ($item) => ($item->menuItem?->cost ?? 0) * $item->quantity);

        if ($table->active_open_bill_id) {
            $openBill = \App\Models\OpenBill::with('groups.items.menuItem')->find($table->active_open_bill_id);
            if ($openBill) {
                foreach ($openBill->groups as $group) {
                    if ($group->fulfillment_type->value === 'dine-in' && $group->table_id === $table->id) {
                        $cost += $group->items->sum(fn ($i) => ($i->menuItem?->cost ?? 0) * $i->quantity);
                    }
                }
            }
        }

        return $cost;
    }

    /**
     * Full bill calculation.
     */
    public function calculateTableBill(Table $table): array
    {
        $table->loadMissing('orderItems.menuItem');

        $durationMinutes = $this->calculateDurationMinutes($table);
        $isFlatRate = $table->billing_mode?->value === 'package';
        $rentalCost = $this->calculateRentalCost($table);
        $orderTotal = $this->calculateOrderTotal($table);
        $orderCost = $this->calculateOrderCost($table);

        return [
            'rental_cost' => $rentalCost,
            'order_total' => $orderTotal,
            'order_cost' => $orderCost,
            'grand_total' => $rentalCost + $orderTotal,
            'duration_minutes' => $durationMinutes,
            'is_flat_rate' => $isFlatRate,
        ];
    }

    /**
     * Calculate open bill totals with member points.
     */
    public function calculateOpenBillTotals(
        int $subtotal,
        int $pointsToRedeem,
        int $memberBalance,
        int $taxPercent,
    ): array {
        $memberPointService = app(MemberPointService::class);

        $allowedPoints = $memberPointService->clampPointsToRedeem($pointsToRedeem, $subtotal, $memberBalance);
        $redeemAmount = $memberPointService->pointsToRupiah($allowedPoints);
        $taxableBase = $subtotal - $redeemAmount;
        $tax = $taxPercent > 0 ? intval(round($taxableBase * ($taxPercent / 100))) : 0;

        return [
            'subtotal' => $subtotal,
            'points_redeemed' => $allowedPoints,
            'redeem_amount' => $redeemAmount,
            'tax' => $tax,
            'total' => $taxableBase + $tax,
        ];
    }
}
