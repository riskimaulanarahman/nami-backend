<?php

namespace App\Services;

use App\Enums\BillType;
use App\Enums\FulfillmentType;
use App\Enums\OpenBillStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethodType;
use App\Enums\SessionType;
use App\Enums\TableStatus;
use App\Events\OrderCompleted;
use App\Events\OrderRefunded;
use App\Models\BusinessSettings;
use App\Models\CashierShift;
use App\Models\Member;
use App\Models\MenuItem;
use App\Models\OpenBill;
use App\Models\OpenBillGroup;
use App\Models\OpenBillGroupItem;
use App\Models\OpenBillInvolvedStaff;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\OrderGroupItem;
use App\Models\OrderInvolvedStaff;
use App\Models\PaymentOption;
use App\Models\Staff;
use App\Models\Table;
use App\Models\TableInvolvedStaff;
use App\Models\TableOrderItem;
use Illuminate\Support\Facades\DB;

class OrderService
{
    private function resolveMenuItemCost(?MenuItem $menuItem): int
    {
        return $menuItem?->effectiveCost() ?? 0;
    }

    private function safeDurationMinutes(\DateTimeInterface $startTime, \DateTimeInterface $endTime): int
    {
        return max(0, intdiv($endTime->getTimestamp() - $startTime->getTimestamp(), 60));
    }

    public function __construct(
        private BillingService $billingService,
        private StockService $stockService,
        private MemberPointService $memberPointService,
        private CashierShiftService $cashierShiftService,
        private PaymentOptionService $paymentOptionService,
    ) {}

    /**
     * Resolve payment method type from option ID/name.
     */
    public function resolvePaymentMethodType(?string $paymentMethodId): PaymentMethodType
    {
        if (!$paymentMethodId) return PaymentMethodType::Cash;
        $option = PaymentOption::find($paymentMethodId);
        if ($option && $option->type->value === 'cash') return PaymentMethodType::Cash;
        return $option ? PaymentMethodType::NonCash : PaymentMethodType::Cash;
    }

    /**
     * Add F&B item to a billiard table session.
     */
    public function addOrderToTable(Table $table, MenuItem $menuItem, Staff $staff): void
    {
        $existing = TableOrderItem::where('table_id', $table->id)
            ->where('menu_item_id', $menuItem->id)
            ->first();

        if ($existing) {
            $existing->increment('quantity');
        } else {
            TableOrderItem::create([
                'table_id' => $table->id,
                'menu_item_id' => $menuItem->id,
                'quantity' => 1,
                'unit_price' => $menuItem->price,
                'added_at' => now(),
            ]);
        }

        $this->stockService->deductForMenuItem($menuItem);
        $this->trackStaffOnTable($table, $staff);
    }

    /**
     * Checkout table (billiard) session.
     */
    public function checkoutTableSession(
        Table $table,
        Staff $staff,
        CashierShift $shift,
        ?string $paymentMethodId = null,
        ?string $paymentMethodName = null,
        ?string $paymentReference = null,
        ?int $cashReceived = null,
    ): Order {
        return DB::transaction(function () use ($table, $staff, $shift, $paymentMethodId, $paymentMethodName, $paymentReference, $cashReceived) {
            $table->loadMissing(['orderItems.menuItem.recipes.ingredient', 'involvedStaff']);

            $bill = $this->billingService->calculateTableBill($table);
            $paymentType = $this->resolvePaymentMethodType($paymentMethodId);
            $resolvedPaymentMethodName = $this->paymentOptionService
                ->resolvePaymentMethodDisplayName($paymentMethodId, $paymentMethodName);
            $cashPayment = $this->resolveCashPayment($paymentType, $bill['grand_total'], $cashReceived);
            $now = now();
            $packageIncludedMinutes = $this->billingService->calculatePackageIncludedMinutes($table);
            $packageTotalPrice = $this->billingService->calculatePackageTotalPrice($table);

            $involvedStaffIds = $table->involvedStaff->pluck('staff_id')->push($staff->id)->unique()->values()->toArray();
            $involvedStaffNames = $table->involvedStaff->pluck('staff_name')->push($staff->name)->unique()->values()->toArray();

            // Create order
            $order = Order::create([
                'table_id' => $table->id,
                'table_name' => $table->name,
                'table_type' => $table->type,
                'session_type' => $table->session_type ?? SessionType::Billiard,
                'bill_type' => $table->billing_mode?->value === 'package' ? BillType::Package : BillType::Billiard,
                'billiard_billing_mode' => $table->billing_mode,
                'start_time' => $table->start_time ?? $now,
                'end_time' => $now,
                'duration_minutes' => $bill['duration_minutes'],
                'session_duration_hours' => intdiv($packageIncludedMinutes, 60),
                'rental_cost' => $bill['rental_cost'],
                'selected_package_id' => $table->selected_package_id,
                'selected_package_name' => $table->selected_package_name,
                'selected_package_hours' => intdiv($packageIncludedMinutes, 60),
                'selected_package_price' => $packageTotalPrice,
                'order_total' => $bill['order_total'],
                'grand_total' => $bill['grand_total'],
                'order_cost' => $bill['order_cost'],
                'served_by' => implode(' → ', $involvedStaffNames),
                'status' => OrderStatus::Completed,
                'payment_method_id' => $paymentMethodId,
                'payment_method_name' => $resolvedPaymentMethodName,
                'payment_method_type' => $paymentType,
                'payment_reference' => $paymentReference,
                'cash_received' => $cashPayment['cash_received'],
                'change_amount' => $cashPayment['change_amount'],
                'cashier_shift_id' => $shift->id,
                'origin_cashier_shift_id' => $table->origin_cashier_shift_id ?? $shift->id,
                'origin_staff_id' => $table->origin_staff_id ?? $staff->id,
                'origin_staff_name' => $table->origin_staff_name ?? $staff->name,
                'is_continued_from_previous_shift' => ($table->origin_cashier_shift_id && $table->origin_cashier_shift_id !== $shift->id),
            ]);

            // Create order group if there are F&B items
            if ($table->orderItems->isNotEmpty()) {
                $group = OrderGroup::create([
                    'order_id' => $order->id,
                    'fulfillment_type' => FulfillmentType::DineIn,
                    'table_id' => $table->id,
                    'table_name' => $table->name,
                    'subtotal' => $table->orderItems->sum(fn($i) => $i->unit_price * $i->quantity),
                ]);

                foreach ($table->orderItems as $item) {
                    OrderGroupItem::create([
                        'order_group_id' => $group->id,
                        'menu_item_id' => $item->menu_item_id,
                        'menu_item_name' => $item->menuItem?->name ?? 'Unknown',
                        'menu_item_emoji' => '',
                        'unit_price' => $item->unit_price,
                        'unit_cost' => $this->resolveMenuItemCost($item->menuItem),
                        'quantity' => $item->quantity,
                        'subtotal' => $item->unit_price * $item->quantity,
                    ]);
                }
            }

            // Include and cleanup linked Open Bill items
            if ($table->active_open_bill_id) {
                $openBill = OpenBill::with('groups.items.menuItem.recipes.ingredient')->find($table->active_open_bill_id);
                if ($openBill) {
                    $dineInGroup = $openBill->groups
                        ->where('fulfillment_type', FulfillmentType::DineIn)
                        ->where('table_id', $table->id)
                        ->first();

                    if ($dineInGroup && $dineInGroup->items->isNotEmpty()) {
                        $group = OrderGroup::create([
                            'order_id' => $order->id,
                            'fulfillment_type' => FulfillmentType::DineIn,
                            'table_id' => $table->id,
                            'table_name' => $table->name,
                            'subtotal' => $dineInGroup->items->sum(fn($i) => $i->unit_price * $i->quantity),
                        ]);

                        foreach ($dineInGroup->items as $item) {
                            OrderGroupItem::create([
                                'order_group_id' => $group->id,
                                'menu_item_id' => $item->menu_item_id,
                                'menu_item_name' => $item->menuItem?->name ?? 'Unknown',
                                'menu_item_emoji' => '',
                                'unit_price' => $item->unit_price,
                                'unit_cost' => $this->resolveMenuItemCost($item->menuItem),
                                'quantity' => $item->quantity,
                                'subtotal' => $item->unit_price * $item->quantity,
                            ]);
                        }
                    }

                    // Cleanup that part of the Open Bill
                    if ($dineInGroup) {
                        $dineInGroup->items()->delete();
                        $dineInGroup->delete();
                    }

                    // If no more items in the open bill, delete it entirely
                    if ($openBill->fresh()->groups->isEmpty()) {
                        $openBill->involvedStaff()->delete();
                        $openBill->delete();
                    }
                }
            }

            // Save involved staff
            foreach ($involvedStaffIds as $i => $sid) {
                OrderInvolvedStaff::create([
                    'order_id' => $order->id,
                    'staff_id' => $sid,
                    'staff_name' => $involvedStaffNames[$i] ?? 'Unknown',
                ]);
            }

            // Record to shift
            $this->cashierShiftService->recordTransaction(
                $shift, $order->grand_total, $paymentType,
                $involvedStaffIds, $involvedStaffNames
            );

            // Reset table
            $table->resetSession();

            event(new OrderCompleted($order));

            return $order;
        });
    }

    /**
     * Checkout an open bill (cafe flow).
     */
    public function checkoutOpenBill(
        OpenBill $openBill,
        Staff $staff,
        CashierShift $shift,
        ?string $paymentMethodId = null,
        ?string $paymentMethodName = null,
        ?string $paymentReference = null,
        ?int $cashReceived = null,
    ): Order {
        return DB::transaction(function () use ($openBill, $staff, $shift, $paymentMethodId, $paymentMethodName, $paymentReference, $cashReceived) {
            $openBill->loadMissing(['groups.items.menuItem.recipes.ingredient', 'involvedStaff', 'member']);

            if ($openBill->isFrozenTableDraft()) {
                return $this->checkoutFrozenTableDraft(
                    $openBill,
                    $staff,
                    $shift,
                    $paymentMethodId,
                    $paymentMethodName,
                    $paymentReference,
                    $cashReceived,
                );
            }

            $settings = BusinessSettings::first();
            $now = now();
            $paymentType = $this->resolvePaymentMethodType($paymentMethodId);
            $resolvedPaymentMethodName = $this->paymentOptionService
                ->resolvePaymentMethodDisplayName($paymentMethodId, $paymentMethodName);

            // Calculate subtotal from groups
            $subtotal = 0;
            $orderCost = 0;
            foreach ($openBill->groups as $group) {
                foreach ($group->items as $item) {
                    $subtotal += $item->unit_price * $item->quantity;
                    $orderCost += $this->resolveMenuItemCost($item->menuItem) * $item->quantity;
                }
            }

            // Member points
            $member = $openBill->member;
            $memberBalance = $member?->points_balance ?? 0;
            $totals = $this->billingService->calculateOpenBillTotals(
                $subtotal,
                $openBill->points_to_redeem,
                $memberBalance,
                $settings?->tax_percent ?? 0,
            );

            $pointsEarned = $this->memberPointService->calculatePointsEarned($subtotal);
            $cashPayment = $this->resolveCashPayment($paymentType, $totals['total'], $cashReceived);

            // Determine bill type
            $billType = $this->determineBillType($openBill);
            $dineInGroup = $openBill->groups->first(fn ($g) => $g->fulfillment_type === FulfillmentType::DineIn);

            $involvedStaffIds = $openBill->involvedStaff->pluck('staff_id')->push($staff->id)->unique()->values()->toArray();
            $involvedStaffNames = $openBill->involvedStaff->pluck('staff_name')->push($staff->name)->unique()->values()->toArray();

            // Create order
            $order = Order::create([
                'table_id' => $dineInGroup?->table_id,
                'table_name' => $billType->value === 'dine-in'
                    ? ($dineInGroup?->table_name ?? "Open Bill {$openBill->code}")
                    : ($billType->value === 'takeaway' ? "Takeaway ({$openBill->code})" : "Open Bill {$openBill->code}"),
                'table_type' => $dineInGroup?->table_id
                    ? (Table::find($dineInGroup->table_id)?->type?->value ?? 'standard')
                    : 'standard',
                'session_type' => SessionType::Cafe,
                'bill_type' => $billType,
                'dining_type' => $billType->value === 'mixed' ? null : ($billType->value === 'dine-in' ? FulfillmentType::DineIn : FulfillmentType::Takeaway),
                'start_time' => $openBill->created_at,
                'end_time' => $now,
                'duration_minutes' => $this->safeDurationMinutes($openBill->created_at, $now),
                'order_total' => $totals['subtotal'],
                'grand_total' => $totals['total'],
                'order_cost' => $orderCost,
                'served_by' => implode(' → ', $involvedStaffNames),
                'status' => OrderStatus::Completed,
                'payment_method_id' => $paymentMethodId,
                'payment_method_name' => $resolvedPaymentMethodName,
                'payment_method_type' => $paymentType,
                'payment_reference' => $paymentReference,
                'cash_received' => $cashPayment['cash_received'],
                'change_amount' => $cashPayment['change_amount'],
                'cashier_shift_id' => $shift->id,
                'origin_cashier_shift_id' => $openBill->origin_cashier_shift_id ?? $shift->id,
                'origin_staff_id' => $openBill->origin_staff_id ?? $staff->id,
                'origin_staff_name' => $openBill->origin_staff_name ?? $staff->name,
                'is_continued_from_previous_shift' => ($openBill->origin_cashier_shift_id && $openBill->origin_cashier_shift_id !== $shift->id),
                'member_id' => $member?->id,
                'member_code' => $member?->code,
                'member_name' => $member?->name,
                'points_earned' => $pointsEarned,
                'points_redeemed' => $totals['points_redeemed'],
                'redeem_amount' => $totals['redeem_amount'],
            ]);

            // Create order groups & items (snapshot)
            foreach ($openBill->groups as $billGroup) {
                if ($billGroup->items->isEmpty()) continue;

                $groupSubtotal = $billGroup->items->sum(fn ($i) => $i->unit_price * $i->quantity);
                $orderGroup = OrderGroup::create([
                    'order_id' => $order->id,
                    'fulfillment_type' => $billGroup->fulfillment_type,
                    'table_id' => $billGroup->table_id,
                    'table_name' => $billGroup->table_name,
                    'subtotal' => $groupSubtotal,
                ]);

                foreach ($billGroup->items as $item) {
                    OrderGroupItem::create([
                        'order_group_id' => $orderGroup->id,
                        'menu_item_id' => $item->menu_item_id,
                        'menu_item_name' => $item->menuItem?->name ?? 'Unknown',
                        'menu_item_emoji' => '',
                        'unit_price' => $item->unit_price,
                        'unit_cost' => $this->resolveMenuItemCost($item->menuItem),
                        'quantity' => $item->quantity,
                        'subtotal' => $item->unit_price * $item->quantity,
                        'note' => $item->note ?: null,
                    ]);
                }
            }

            // Save involved staff
            foreach ($involvedStaffIds as $i => $sid) {
                OrderInvolvedStaff::create([
                    'order_id' => $order->id,
                    'staff_id' => $sid,
                    'staff_name' => $involvedStaffNames[$i] ?? 'Unknown',
                ]);
            }

            // Process member points
            if ($member) {
                if ($totals['points_redeemed'] > 0) {
                    $this->memberPointService->redeem($member, $order->id, $totals['points_redeemed'], $openBill->code);
                }
                if ($pointsEarned > 0) {
                    $this->memberPointService->earn($member, $order->id, $subtotal, $openBill->code);
                }
            }

            // Unlink tables
            $tableIds = $openBill->groups
                ->where('fulfillment_type', FulfillmentType::DineIn)
                ->whereNotNull('table_id')
                ->pluck('table_id')
                ->toArray();

            if (!empty($tableIds)) {
                Table::whereIn('id', $tableIds)->update(['active_open_bill_id' => null]);
            }

            // Record to shift
            $this->cashierShiftService->recordTransaction(
                $shift, $order->grand_total, $paymentType,
                $involvedStaffIds, $involvedStaffNames
            );

            // Delete open bill
            $openBill->groups()->each(fn ($g) => $g->items()->delete());
            $openBill->groups()->delete();
            $openBill->involvedStaff()->delete();
            $openBill->delete();

            event(new OrderCompleted($order));

            return $order;
        });
    }

    private function checkoutFrozenTableDraft(
        OpenBill $openBill,
        Staff $staff,
        CashierShift $shift,
        ?string $paymentMethodId = null,
        ?string $paymentMethodName = null,
        ?string $paymentReference = null,
        ?int $cashReceived = null,
    ): Order {
        $paymentType = $this->resolvePaymentMethodType($paymentMethodId);
        $resolvedPaymentMethodName = $this->paymentOptionService
            ->resolvePaymentMethodDisplayName($paymentMethodId, $paymentMethodName);
        $orderTotal = $openBill->groups->sum(
            fn (OpenBillGroup $group) => $group->items->sum(fn ($item) => $item->unit_price * $item->quantity)
        );
        $grandTotal = $orderTotal + (int) ($openBill->session_charge_total ?? 0);
        $cashPayment = $this->resolveCashPayment($paymentType, $grandTotal, $cashReceived);
        $now = now();

        $involvedStaffIds = $openBill->involvedStaff->pluck('staff_id')->push($staff->id)->unique()->values()->toArray();
        $involvedStaffNames = $openBill->involvedStaff->pluck('staff_name')->push($staff->name)->unique()->values()->toArray();

        $order = Order::create([
            'table_id' => $openBill->source_table_id,
            'table_name' => $openBill->source_table_name ?: ($openBill->customer_name ?: "Draft {$openBill->code}"),
            'table_type' => $openBill->source_table_type,
            'session_type' => $openBill->session_type ?? SessionType::Billiard,
            'bill_type' => ($openBill->selected_package_price ?? 0) > 0 ? BillType::Package : BillType::Billiard,
            'billiard_billing_mode' => $openBill->billing_mode,
            'start_time' => $openBill->session_started_at ?? $openBill->created_at ?? $now,
            'end_time' => $openBill->session_ended_at ?? $now,
            'duration_minutes' => (int) ($openBill->duration_minutes ?? 0),
            'session_duration_hours' => (int) ($openBill->selected_package_hours ?? 0),
            'rental_cost' => (int) ($openBill->session_charge_total ?? 0),
            'selected_package_id' => null,
            'selected_package_name' => $openBill->selected_package_name,
            'selected_package_hours' => (int) ($openBill->selected_package_hours ?? 0),
            'selected_package_price' => (int) ($openBill->selected_package_price ?? 0),
            'order_total' => $orderTotal,
            'grand_total' => $grandTotal,
            'order_cost' => $openBill->groups->sum(fn (OpenBillGroup $group) => $group->items->sum(
                fn ($item) => $this->resolveMenuItemCost($item->menuItem) * $item->quantity
            )),
            'served_by' => implode(' → ', $involvedStaffNames),
            'status' => OrderStatus::Completed,
            'payment_method_id' => $paymentMethodId,
            'payment_method_name' => $resolvedPaymentMethodName,
            'payment_method_type' => $paymentType,
            'payment_reference' => $paymentReference,
            'cash_received' => $cashPayment['cash_received'],
            'change_amount' => $cashPayment['change_amount'],
            'cashier_shift_id' => $shift->id,
            'origin_cashier_shift_id' => $openBill->origin_cashier_shift_id ?? $shift->id,
            'origin_staff_id' => $openBill->origin_staff_id ?? $staff->id,
            'origin_staff_name' => $openBill->origin_staff_name ?? $staff->name,
            'is_continued_from_previous_shift' => ($openBill->origin_cashier_shift_id && $openBill->origin_cashier_shift_id !== $shift->id),
            'member_id' => $openBill->member?->id,
            'member_code' => $openBill->member?->code,
            'member_name' => $openBill->member?->name,
            'points_earned' => 0,
            'points_redeemed' => 0,
            'redeem_amount' => 0,
        ]);

        foreach ($openBill->groups as $billGroup) {
            if ($billGroup->items->isEmpty()) {
                continue;
            }

            $groupSubtotal = $billGroup->items->sum(fn ($item) => $item->unit_price * $item->quantity);
            $orderGroup = OrderGroup::create([
                'order_id' => $order->id,
                'fulfillment_type' => $billGroup->fulfillment_type,
                'table_id' => $openBill->source_table_id,
                'table_name' => $billGroup->table_name ?: $openBill->source_table_name,
                'subtotal' => $groupSubtotal,
            ]);

            foreach ($billGroup->items as $item) {
                OrderGroupItem::create([
                    'order_group_id' => $orderGroup->id,
                    'menu_item_id' => $item->menu_item_id,
                    'menu_item_name' => $item->menuItem?->name ?? 'Unknown',
                    'menu_item_emoji' => '',
                    'unit_price' => $item->unit_price,
                    'unit_cost' => $this->resolveMenuItemCost($item->menuItem),
                    'quantity' => $item->quantity,
                    'subtotal' => $item->unit_price * $item->quantity,
                    'note' => $item->note ?: null,
                ]);
            }
        }

        foreach ($involvedStaffIds as $i => $sid) {
            OrderInvolvedStaff::create([
                'order_id' => $order->id,
                'staff_id' => $sid,
                'staff_name' => $involvedStaffNames[$i] ?? 'Unknown',
            ]);
        }

        $this->cashierShiftService->recordTransaction(
            $shift,
            $order->grand_total,
            $paymentType,
            $involvedStaffIds,
            $involvedStaffNames,
        );

        $openBill->groups()->each(fn ($group) => $group->items()->delete());
        $openBill->groups()->delete();
        $openBill->involvedStaff()->delete();
        $openBill->delete();

        event(new OrderCompleted($order));

        return $order;
    }

    /**
     * Refund an order.
     */
    public function refundOrder(
        Order $order,
        Staff $staff,
        CashierShift $shift,
        string $reason,
        array $authorization = [],
    ): Order
    {
        return DB::transaction(function () use ($order, $staff, $shift, $reason, $authorization) {
            if ($order->status === OrderStatus::Refunded) {
                throw new \RuntimeException('Order ini sudah di-refund.');
            }

            $order->update([
                'status' => OrderStatus::Refunded,
                'refunded_at' => now(),
                'refunded_by' => $staff->name,
                'refund_reason' => $reason,
                'refund_authorization_method' => $authorization['method'] ?? null,
                'refund_authorized_by' => $authorization['authorized_by'] ?? null,
                'refund_authorized_role' => $authorization['authorized_role'] ?? null,
                'refund_owner_email' => $authorization['owner_email'] ?? null,
                'refunded_in_cashier_shift_id' => $shift->id,
            ]);

            $this->cashierShiftService->recordRefund(
                $shift,
                $order->grand_total,
                $order->payment_method_type,
                [$staff->id],
                [$staff->name],
            );

            event(new OrderRefunded($order, $reason));

            return $order->fresh();
        });
    }

    private function determineBillType(OpenBill $bill): BillType
    {
        $hasDineIn = $bill->groups->contains(fn ($g) => $g->fulfillment_type === FulfillmentType::DineIn && $g->items->isNotEmpty());
        $hasTakeaway = $bill->groups->contains(fn ($g) => $g->fulfillment_type === FulfillmentType::Takeaway && $g->items->isNotEmpty());

        if ($hasDineIn && $hasTakeaway) return BillType::Mixed;
        if ($hasTakeaway) return BillType::Takeaway;
        return BillType::DineIn;
    }

    private function resolveCashPayment(
        PaymentMethodType $paymentType,
        int $grandTotal,
        ?int $cashReceived,
    ): array {
        if ($paymentType !== PaymentMethodType::Cash) {
            return [
                'cash_received' => null,
                'change_amount' => null,
            ];
        }

        $received = $cashReceived ?? $grandTotal;
        if ($received < $grandTotal) {
            throw new \RuntimeException('Nominal pembayaran kurang dari total.');
        }

        return [
            'cash_received' => $received,
            'change_amount' => $received - $grandTotal,
        ];
    }

    private function trackStaffOnTable(Table $table, Staff $staff): void
    {
        TableInvolvedStaff::firstOrCreate(
            ['table_id' => $table->id, 'staff_id' => $staff->id],
            ['staff_name' => $staff->name]
        );
    }
}
