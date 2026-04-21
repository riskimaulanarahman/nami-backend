<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
            'layout_position' => $this->whenLoaded('layoutPosition'),
            'order_items' => $this->whenLoaded('orderItems'),
            'involved_staff' => $this->whenLoaded('involvedStaff'),
        ];
    }
}
