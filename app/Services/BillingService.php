<?php

namespace App\Services;

use App\Models\Table;
use Illuminate\Support\Carbon;

class BillingService
{
    private const PACKAGE_REMINDER_INTERVAL_MINUTES = 5;

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

    public function calculatePackageExpiredAt(Table $table): ?Carbon
    {
        if ($table->package_expired_at instanceof Carbon) {
            return $table->package_expired_at->copy();
        }

        $includedMinutes = $this->calculatePackageIncludedMinutes($table);
        if (!$table->start_time || $includedMinutes <= 0) {
            return null;
        }

        return $table->start_time->copy()->addMinutes($includedMinutes);
    }

    public function calculateLastPackageReminderAt(Table $table): ?Carbon
    {
        return $table->package_reminder_shown_at instanceof Carbon
            ? $table->package_reminder_shown_at->copy()
            : null;
    }

    public function calculateNextPackageReminderDueAt(Table $table): ?Carbon
    {
        $expiredAt = $this->calculatePackageExpiredAt($table);
        if (!$expiredAt) {
            return null;
        }

        $lastReminderAt = $this->calculateLastPackageReminderAt($table);
        if (!$lastReminderAt) {
            return $expiredAt;
        }

        return $lastReminderAt->copy()->addMinutes(self::PACKAGE_REMINDER_INTERVAL_MINUTES);
    }

    public function calculateRemainingPackageMinutes(Table $table): int
    {
        $expiredAt = $this->calculatePackageExpiredAt($table);
        if (!$expiredAt) {
            return 0;
        }

        return intval(floor(now()->diffInSeconds($expiredAt, false) / 60));
    }

    public function calculateOverrunPackageMinutes(Table $table): int
    {
        return max(0, -$this->calculateRemainingPackageMinutes($table));
    }

    public function calculateAccruedOverrunCost(Table $table): int
    {
        return intval($table->accrued_overrun_cost ?? 0);
    }

    public function calculateActiveOverrunMinutes(Table $table): int
    {
        $anchor = $table->overrun_started_at;
        if (!$anchor instanceof Carbon) {
            $anchor = $this->calculatePackageExpiredAt($table);
        }
        if (!$anchor) {
            return 0;
        }

        return max(0, intval(floor($anchor->diffInSeconds(now(), false) / 60)));
    }

    private function calculateOpenBillRentalCost(int $durationMinutes, int $hourlyRate): int
    {
        if ($durationMinutes <= 0 || $hourlyRate <= 0) {
            return 0;
        }

        return intval(ceil($durationMinutes / 60)) * $hourlyRate;
    }

    public function calculateOverrunRentalCost(Table $table): int
    {
        return $this->calculateOpenBillRentalCost(
            $this->calculateActiveOverrunMinutes($table),
            $table->hourly_rate,
        );
    }

    /**
     * Calculate billiard rental cost.
     */
    public function calculateRentalCost(Table $table): int
    {
        $durationMinutes = $this->calculateDurationMinutes($table);
        $packageTotalPrice = $this->calculatePackageTotalPrice($table);
        $accruedOverrunCost = $this->calculateAccruedOverrunCost($table);
        $packageExpiredAt = $this->calculatePackageExpiredAt($table);

        if ($table->billing_mode?->value === 'package') {
            return $packageTotalPrice + $accruedOverrunCost;
        }

        if ($packageExpiredAt && $packageTotalPrice > 0) {
            return $packageTotalPrice +
                $accruedOverrunCost +
                $this->calculateOverrunRentalCost($table);
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
        $table->loadMissing('orderItems.menuItem.recipes.ingredient');
        $cost = $table->orderItems->sum(fn ($item) => ($item->menuItem?->effectiveCost() ?? 0) * $item->quantity);

        if ($table->active_open_bill_id) {
            $openBill = \App\Models\OpenBill::with('groups.items.menuItem.recipes.ingredient')->find($table->active_open_bill_id);
            if ($openBill) {
                foreach ($openBill->groups as $group) {
                    if ($group->fulfillment_type->value === 'dine-in' && $group->table_id === $table->id) {
                        $cost += $group->items->sum(fn ($i) => ($i->menuItem?->effectiveCost() ?? 0) * $i->quantity);
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
                'menu_item_emoji' => '',
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
                            'menu_item_emoji' => '',
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

    public function synchronizeExpiredPackageSession(Table $table): Table
    {
        $includedMinutes = $this->calculatePackageIncludedMinutes($table);
        if (!$table->start_time || $includedMinutes <= 0) {
            return $table;
        }

        $expiredAt = $this->calculatePackageExpiredAt($table);
        $updates = [];

        if (!$table->package_expired_at && $expiredAt) {
            $updates['package_expired_at'] = $expiredAt;
        }

        if (
            $expiredAt &&
            $table->billing_mode?->value === 'package' &&
            $expiredAt->lessThanOrEqualTo(now())
        ) {
            $updates['billing_mode'] = 'open-bill';
            $updates['overrun_started_at'] = $table->overrun_started_at ?? $expiredAt;
        }

        if (!empty($updates)) {
            $table->forceFill($updates)->save();
        }

        return $table;
    }

    /**
     * Full bill calculation.
     */
    public function calculateTableBill(Table $table): array
    {
        $table = $this->synchronizeExpiredPackageSession($table);
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
        $packageExpiredAt = $this->calculatePackageExpiredAt($table);
        $lastReminderAt = $this->calculateLastPackageReminderAt($table);
        $nextReminderDueAt = $this->calculateNextPackageReminderDueAt($table);
        $activeOverrunMinutes = $this->calculateActiveOverrunMinutes($table);
        $accruedOverrunCost = $this->calculateAccruedOverrunCost($table);

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
            'active_overrun_minutes' => $activeOverrunMinutes,
            'accrued_overrun_cost' => $accruedOverrunCost,
            'is_package_expired' => $includedPackageMinutes > 0 && $remainingPackageMinutes <= 0,
            'is_in_grace_period' => $includedPackageMinutes > 0 && $remainingPackageMinutes <= 0,
            'is_auto_converted_to_open_bill' => $packageExpiredAt !== null && $table->billing_mode?->value === 'open-bill' && $packageTotalPrice > 0,
            'package_total_price' => $packageTotalPrice,
            'package_expired_at' => $packageExpiredAt,
            'last_package_reminder_at' => $lastReminderAt,
            'next_package_reminder_due_at' => $nextReminderDueAt,
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
