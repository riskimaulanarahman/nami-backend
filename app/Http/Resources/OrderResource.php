<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->id, // ULID works as code
            'table_id' => $this->table_id,
            'table_name' => $this->table_name,
            'table_type' => $this->table_type,
            'session_type' => $this->session_type,
            'bill_type' => $this->bill_type,
            'billiard_billing_mode' => $this->billiard_billing_mode,
            'dining_type' => $this->dining_type,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'duration_minutes' => $this->duration_minutes,
            'session_duration_hours' => $this->session_duration_hours,
            'rental_cost' => $this->rental_cost,
            'selected_package_id' => $this->selected_package_id,
            'selected_package_name' => $this->selected_package_name,
            'selected_package_hours' => $this->selected_package_hours,
            'selected_package_price' => $this->selected_package_price,
            'order_total' => $this->order_total,
            'order_cost' => $this->order_cost,
            'grand_total' => $this->grand_total,
            'status' => $this->status,
            'payment_method_name' => $this->payment_method_name,
            'payment_method_id' => $this->payment_method_id,
            'payment_method_type' => $this->payment_method_type,
            'payment_reference' => $this->payment_reference,
            'cash_received' => $this->cash_received,
            'change_amount' => $this->change_amount,
            'served_by' => $this->served_by,
            'refunded_at' => $this->refunded_at,
            'refunded_by' => $this->refunded_by,
            'refund_reason' => $this->refund_reason,
            'refund_authorization_method' => $this->refund_authorization_method,
            'refund_authorized_by' => $this->refund_authorized_by,
            'refund_authorized_role' => $this->refund_authorized_role,
            'refund_owner_email' => $this->refund_owner_email,
            'cashier_shift_id' => $this->cashier_shift_id,
            'refunded_in_cashier_shift_id' => $this->refunded_in_cashier_shift_id,
            'origin_cashier_shift_id' => $this->origin_cashier_shift_id,
            'origin_staff_id' => $this->origin_staff_id,
            'origin_staff_name' => $this->origin_staff_name,
            'is_continued_from_previous_shift' => $this->is_continued_from_previous_shift,
            'member_id' => $this->member_id,
            'member_code' => $this->member_code,
            'member_name' => $this->member_name,
            'points_earned' => $this->points_earned,
            'points_redeemed' => $this->points_redeemed,
            'redeem_amount' => $this->redeem_amount,
            'created_at' => $this->created_at,
            'groups' => $this->whenLoaded('groups'),
            'involved_staff' => $this->whenLoaded('involvedStaff'),
        ];
    }
}
