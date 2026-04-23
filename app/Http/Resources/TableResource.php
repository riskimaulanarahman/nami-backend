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
        $elapsedMinutes = $billingService->calculateDurationMinutes($this->resource);
        $includedPackageMinutes = $billingService->calculatePackageIncludedMinutes($this->resource);
        $remainingPackageMinutes = $billingService->calculateRemainingPackageMinutes($this->resource);
        $overrunPackageMinutes = $billingService->calculateOverrunPackageMinutes($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'status' => $this->status,
            'hourly_rate' => $this->hourly_rate,
            'active_open_bill_id' => $this->active_open_bill_id,
            'start_time' => $this->start_time,
            'session_type' => $this->session_type,
            'billing_mode' => $this->billing_mode,
            'selected_package' => [
                'id' => $this->selected_package_id,
                'name' => $this->selected_package_name,
                'hours' => $this->selected_package_hours,
                'price' => $this->selected_package_price,
            ],
            'elapsed_minutes' => $elapsedMinutes,
            'package_included_minutes_total' => $includedPackageMinutes,
            'remaining_package_minutes' => $remainingPackageMinutes,
            'overrun_package_minutes' => $overrunPackageMinutes,
            'package_total_price' => $billingService->calculatePackageTotalPrice($this->resource),
            'is_package_expired' => $includedPackageMinutes > 0 && $remainingPackageMinutes <= 0,
            'is_in_grace_period' => $includedPackageMinutes > 0 && $remainingPackageMinutes < 0,
            'package_reminder_shown_at' => $this->package_reminder_shown_at,
            'layout_position' => $this->whenLoaded('layoutPosition'),
            'order_items' => $this->whenLoaded('orderItems'),
            'involved_staff' => $this->whenLoaded('involvedStaff'),
        ];
    }
}
