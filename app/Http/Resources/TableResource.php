<?php

namespace App\Http\Resources;

use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $billingService = app(BillingService::class);
        $table = $billingService->synchronizeExpiredPackageSession($this->resource);
        $elapsedMinutes = $billingService->calculateDurationMinutes($table);
        $includedPackageMinutes = $billingService->calculatePackageIncludedMinutes($table);
        $remainingPackageMinutes = $billingService->calculateRemainingPackageMinutes($table);
        $overrunPackageMinutes = $billingService->calculateOverrunPackageMinutes($table);
        $packageExpiredAt = $billingService->calculatePackageExpiredAt($table);
        $lastReminderAt = $billingService->calculateLastPackageReminderAt($table);
        $nextReminderDueAt = $billingService->calculateNextPackageReminderDueAt($table);
        $packageTotalPrice = $billingService->calculatePackageTotalPrice($table);

        return [
            'id' => $table->id,
            'name' => $table->name,
            'type' => $table->type,
            'status' => $table->status,
            'hourly_rate' => $table->hourly_rate,
            'active_open_bill_id' => $table->active_open_bill_id,
            'start_time' => $table->start_time,
            'session_type' => $table->session_type,
            'billing_mode' => $table->billing_mode,
            'selected_package' => [
                'id' => $table->selected_package_id,
                'name' => $table->selected_package_name,
                'hours' => $table->selected_package_hours,
                'price' => $table->selected_package_price,
            ],
            'elapsed_minutes' => $elapsedMinutes,
            'package_included_minutes_total' => $includedPackageMinutes,
            'remaining_package_minutes' => $remainingPackageMinutes,
            'overrun_package_minutes' => $overrunPackageMinutes,
            'active_overrun_minutes' => $billingService->calculateActiveOverrunMinutes($table),
            'accrued_overrun_cost' => $billingService->calculateAccruedOverrunCost($table),
            'package_total_price' => $packageTotalPrice,
            'package_expired_at' => $packageExpiredAt,
            'is_package_expired' => $includedPackageMinutes > 0 && $remainingPackageMinutes <= 0,
            'is_in_grace_period' => $includedPackageMinutes > 0 && $remainingPackageMinutes <= 0,
            'is_auto_converted_to_open_bill' => $packageExpiredAt !== null && $table->billing_mode?->value === 'open-bill' && $packageTotalPrice > 0,
            'package_reminder_shown_at' => $table->package_reminder_shown_at,
            'last_package_reminder_at' => $lastReminderAt,
            'next_package_reminder_due_at' => $nextReminderDueAt,
            'layout_position' => $this->whenLoaded('layoutPosition'),
            'order_items' => $this->whenLoaded('orderItems'),
            'involved_staff' => $this->whenLoaded('involvedStaff'),
        ];
    }
}
