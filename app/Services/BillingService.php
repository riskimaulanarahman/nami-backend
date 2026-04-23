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

    public function calculateDurationMinutes(Table $table): int
    {
        if (!$table->start_time) return 0;

        return intval(floor($this->elapsedSecondsSince($table->start_time) / 60));
    }

    public function calculatePackageIncludedMinutes(Table $table): int
    {
        $stored = intval($table->package_minutes_total ?? 0);
        if ($stored > 0) {
            return $stored;
        }

        $legacyHours = intval($table->selected_package_hours ?? 0);
        return max(0, $legacyHours * 60);
    }

    public function calculatePackageTotalPrice(Table $table): int
    {
        $stored = intval($table->package_total_price ?? 0);
        if ($stored > 0) {
            return $stored;
        }

        return intval($table->selected_package_price ?? 0);
    }

    public function calculateRemainingPackageMinutes(Table $table): int
    {
        $includedMinutes = $this->calculatePackageIncludedMinutes($table);
        if ($includedMinutes <= 0) {
            return 0;
        }

        return $includedMinutes - $this->calculateDurationMinutes($table);
    }

    public function calculateOverrunPackageMinutes(Table $table): int
    {
        return max(0, -$this->calculateRemainingPackageMinutes($table));
    }

    private function calculateOpenBillRentalCost(int $durationMinutes, int $hourlyRate): int
    {
        if ($durationMinutes <= 0 || $hourlyRate <= 0) {
            return 0;
        }

        return intval(ceil($durationMinutes / 60)) * $hourlyRate;
    }

    /**
     * Calculate billiard rental cost.
     */
    public function calculateRentalCost(Table $table): int
    {
        $durationMinutes = $this->calculateDurationMinutes($table);
        $includedPackageMinutes = $this->calculatePackageIncludedMinutes($table);
        $packageTotalPrice = $this->calculatePackageTotalPrice($table);

        if ($table->billing_mode?->value === 'package') {
            return $packageTotalPrice;
        }

        if ($includedPackageMinutes > 0 && $packageTotalPrice > 0) {
            $overrunMinutes = max(0, $durationMinutes - $includedPackageMinutes);
            return $packageTotalPrice +
                $this->calculateOpenBillRentalCost($overrunMinutes, $table->hourly_rate);
        }

        return $this->calculateOpenBillRentalCost($durationMinutes, $table->hourly_rate);
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

    public function calculateOrderItems(Table $table): array
    {
        $table->loadMissing('orderItems.menuItem');
        $items = $table->orderItems->map(function ($item) {
            return [
                'menu_item_id' => $item->menu_item_id,
                'menu_item_name' => $item->menuItem?->name ?? 'Unknown',
                'menu_item_emoji' => $item->menuItem?->emoji ?? '🍽️',
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal' => $item->unit_price * $item->quantity,
            ];
        })->values()->all();

        if ($table->active_open_bill_id) {
            $openBill = \App\Models\OpenBill::with('groups.items.menuItem')->find($table->active_open_bill_id);
            if ($openBill) {
                foreach ($openBill->groups as $group) {
                    if ($group->fulfillment_type->value !== 'dine-in' || $group->table_id !== $table->id) {
                        continue;
                    }

                    foreach ($group->items as $item) {
                        $items[] = [
                            'menu_item_id' => $item->menu_item_id,
                            'menu_item_name' => $item->menuItem?->name ?? 'Unknown',
                            'menu_item_emoji' => $item->menuItem?->emoji ?? '🍽️',
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'subtotal' => $item->unit_price * $item->quantity,
                            'note' => $item->note,
                        ];
                    }
                }
            }
        }

        return $items;
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
        $includedPackageMinutes = $this->calculatePackageIncludedMinutes($table);
        $remainingPackageMinutes = $this->calculateRemainingPackageMinutes($table);
        $overrunPackageMinutes = max(0, -$remainingPackageMinutes);
        $packageTotalPrice = $this->calculatePackageTotalPrice($table);

        return [
            'rental_cost' => $rentalCost,
            'order_total' => $orderTotal,
            'order_cost' => $orderCost,
            'grand_total' => $rentalCost + $orderTotal,
            'duration_minutes' => $durationMinutes,
            'is_flat_rate' => $isFlatRate,
            'package_included_minutes_total' => $includedPackageMinutes,
            'remaining_package_minutes' => $remainingPackageMinutes,
            'overrun_package_minutes' => $overrunPackageMinutes,
            'is_package_expired' => $includedPackageMinutes > 0 && $remainingPackageMinutes <= 0,
            'is_in_grace_period' => $includedPackageMinutes > 0 && $remainingPackageMinutes < 0,
            'package_total_price' => $packageTotalPrice,
            'items' => $this->calculateOrderItems($table),
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
