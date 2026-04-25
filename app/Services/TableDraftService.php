<?php

namespace App\Services;

use App\Enums\FulfillmentType;
use App\Enums\OpenBillStatus;
use App\Models\CashierShift;
use App\Models\OpenBill;
use App\Models\OpenBillGroup;
use App\Models\OpenBillInvolvedStaff;
use App\Models\Staff;
use App\Models\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TableDraftService
{
    public function __construct(
        private BillingService $billingService,
    ) {}

    public function closeToDraft(
        Table $table,
        Staff $staff,
        CashierShift $shift,
    ): OpenBill {
        return DB::transaction(function () use ($table, $staff, $shift) {
            $table->loadMissing([
                'orderItems.menuItem',
                'involvedStaff',
                'activeOpenBill.groups.items.menuItem',
                'activeOpenBill.involvedStaff',
                'activeOpenBill.member',
            ]);

            $linkedOpenBill = $table->activeOpenBill;
            $linkedTableGroup = $linkedOpenBill?->groups
                ->where('fulfillment_type', FulfillmentType::DineIn)
                ->where('table_id', $table->id)
                ->first();

            $bill = $this->billingService->calculateTableBill($table);
            $endedAt = now();

            $draft = OpenBill::create([
                'code' => $this->generateOpenBillCode(),
                'customer_name' => $this->resolveCustomerName($table, $linkedOpenBill),
                'member_id' => $linkedOpenBill?->member_id,
                'points_to_redeem' => 0,
                'status' => OpenBillStatus::Draft,
                'origin_cashier_shift_id' => $table->origin_cashier_shift_id ?? $shift->id,
                'origin_staff_id' => $table->origin_staff_id ?? $staff->id,
                'origin_staff_name' => $table->origin_staff_name ?? $staff->name,
                'source_table_id' => $table->id,
                'source_table_name' => $table->name,
                'source_table_type' => $table->type,
                'session_type' => $table->session_type,
                'billing_mode' => $table->billing_mode,
                'session_started_at' => $table->start_time,
                'session_ended_at' => $endedAt,
                'duration_minutes' => $bill['duration_minutes'] ?? 0,
                'session_charge_name' => $this->resolveSessionChargeName($table),
                'session_charge_total' => $bill['rental_cost'] ?? 0,
                'selected_package_name' => $table->selected_package_name,
                'selected_package_hours' => $table->selected_package_hours ?? 0,
                'selected_package_price' => $table->selected_package_price ?? 0,
                'locked_final' => true,
            ]);

            $this->copyInvolvedStaff($draft, $table, $linkedOpenBill, $staff);
            $this->copyTableItems($draft, $table, $linkedTableGroup);

            if ($linkedTableGroup) {
                $linkedTableGroup->items()->delete();
                $linkedTableGroup->delete();
            }

            if ($linkedOpenBill) {
                $linkedOpenBill->refresh()->loadMissing('groups.items');
                if ($linkedOpenBill->groups->isEmpty()) {
                    $linkedOpenBill->involvedStaff()->delete();
                    $linkedOpenBill->delete();
                }
            }

            $table->resetSession();

            return $draft->fresh()->load(['groups.items.menuItem', 'involvedStaff', 'member']);
        });
    }

    private function resolveCustomerName(Table $table, ?OpenBill $linkedOpenBill): string
    {
        $linkedName = trim((string) ($linkedOpenBill?->customer_name ?? ''));
        if ($linkedName !== '') {
            return $linkedName;
        }

        return $table->name;
    }

    private function resolveSessionChargeName(Table $table): string
    {
        $selectedPackageName = trim((string) ($table->selected_package_name ?? ''));
        if ($selectedPackageName !== '') {
            return $selectedPackageName;
        }

        return 'Sewa Meja';
    }

    private function copyInvolvedStaff(
        OpenBill $draft,
        Table $table,
        ?OpenBill $linkedOpenBill,
        Staff $staff,
    ): void {
        $staffRows = collect()
            ->merge($table->involvedStaff->map(fn ($row) => [
                'staff_id' => $row->staff_id,
                'staff_name' => $row->staff_name,
            ]))
            ->merge(($linkedOpenBill?->involvedStaff ?? collect())->map(fn ($row) => [
                'staff_id' => $row->staff_id,
                'staff_name' => $row->staff_name,
            ]))
            ->push([
                'staff_id' => $staff->id,
                'staff_name' => $staff->name,
            ])
            ->filter(fn (array $row) => !empty($row['staff_id']))
            ->unique('staff_id')
            ->values();

        foreach ($staffRows as $staffRow) {
            OpenBillInvolvedStaff::create([
                'open_bill_id' => $draft->id,
                'staff_id' => $staffRow['staff_id'],
                'staff_name' => $staffRow['staff_name'] ?? 'Unknown',
            ]);
        }
    }

    private function copyTableItems(
        OpenBill $draft,
        Table $table,
        ?OpenBillGroup $linkedTableGroup,
    ): void {
        $tableItems = collect($table->orderItems)
            ->map(fn ($item) => [
                'menu_item_id' => $item->menu_item_id,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'added_at' => $item->added_at ?? now(),
                'note' => null,
            ]);

        $linkedItems = collect($linkedTableGroup?->items ?? [])
            ->map(fn ($item) => [
                'menu_item_id' => $item->menu_item_id,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'added_at' => $item->added_at ?? now(),
                'note' => $item->note,
            ]);

        $items = $tableItems
            ->merge($linkedItems)
            ->values();

        if ($items->isEmpty()) {
            return;
        }

        $group = OpenBillGroup::create([
            'open_bill_id' => $draft->id,
            'fulfillment_type' => FulfillmentType::DineIn,
            'table_id' => null,
            'table_name' => $table->name,
            'subtotal' => 0,
        ]);

        foreach ($items as $item) {
            $group->items()->create([
                'menu_item_id' => $item['menu_item_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'added_at' => $item['added_at'],
                'note' => $item['note'],
            ]);
        }

        $group->recalculateSubtotal();
    }

    private function generateOpenBillCode(): string
    {
        do {
            $code = sprintf(
                'OB-%s-%s',
                now()->format('ymdHis'),
                Str::upper(Str::random(4)),
            );
        } while (OpenBill::query()->withoutGlobalScopes()->where('code', $code)->exists());

        return $code;
    }
}
