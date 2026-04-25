<?php

namespace App\Services;

use App\Enums\FulfillmentType;
use App\Enums\OpenBillCloseReason;
use App\Enums\OpenBillStatus;
use App\Models\CashierShift;
use App\Models\OpenBill;
use App\Models\OpenBillGroup;
use App\Models\OpenBillInvolvedStaff;
use App\Models\Staff;
use App\Models\Table;
use Illuminate\Support\Carbon;
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
            $lockedTable = $this->lockTableForDraft($table->id);

            return $this->closeLockedTableToDraft(
                $lockedTable,
                staff: $staff,
                shift: $shift,
                endedAt: now(),
                closeReason: OpenBillCloseReason::ManualTableClose,
            );
        });
    }

    public function closeExpiredPackageToDraft(Table $table): ?OpenBill
    {
        return DB::transaction(function () use ($table) {
            $lockedTable = $this->lockTableForDraft($table->id);
            $expiredAt = $lockedTable->package_expired_at;

            if (
                !$expiredAt ||
                $lockedTable->status?->value !== 'occupied' ||
                $lockedTable->session_type?->value !== 'billiard' ||
                $lockedTable->billing_mode?->value !== 'package' ||
                $expiredAt->greaterThan(now())
            ) {
                return null;
            }

            return $this->closeLockedTableToDraft(
                $lockedTable,
                endedAt: $expiredAt,
                closeReason: OpenBillCloseReason::PackageExpiredAuto,
                freezeToPackageEnd: true,
            );
        });
    }

    private function lockTableForDraft(int $tableId): Table
    {
        return Table::query()
            ->withoutGlobalScopes()
            ->with([
                'orderItems.menuItem',
                'involvedStaff',
                'activeOpenBill.groups.items.menuItem',
                'activeOpenBill.involvedStaff',
                'activeOpenBill.member',
            ])
            ->lockForUpdate()
            ->findOrFail($tableId);
    }

    private function closeLockedTableToDraft(
        Table $table,
        ?Staff $staff = null,
        ?CashierShift $shift = null,
        ?Carbon $endedAt = null,
        OpenBillCloseReason $closeReason = OpenBillCloseReason::ManualTableClose,
        bool $freezeToPackageEnd = false,
    ): OpenBill {
        $linkedOpenBill = $table->activeOpenBill;
        $linkedTableGroup = $linkedOpenBill?->groups
            ->where('fulfillment_type', FulfillmentType::DineIn)
            ->where('table_id', $table->id)
            ->first();

        $draftEndAt = $endedAt ?? now();
        $snapshot = $freezeToPackageEnd
            ? $this->buildFrozenExpiredPackageSnapshot($table, $draftEndAt)
            : $this->buildLiveTableDraftSnapshot($table, $draftEndAt);

        $draft = OpenBill::create([
            'tenant_id' => $table->tenant_id,
            'code' => $this->generateOpenBillCode(),
            'customer_name' => $this->resolveCustomerName($table, $linkedOpenBill),
            'member_id' => $linkedOpenBill?->member_id,
            'points_to_redeem' => 0,
            'status' => OpenBillStatus::Draft,
            'origin_cashier_shift_id' => $table->origin_cashier_shift_id ?? $shift?->id,
            'origin_staff_id' => $table->origin_staff_id ?? $staff?->id,
            'origin_staff_name' => $table->origin_staff_name ?? $staff?->name,
            'source_table_id' => $table->id,
            'source_table_name' => $table->name,
            'source_table_type' => $table->type,
            'session_type' => $table->session_type,
            'billing_mode' => $table->billing_mode,
            'session_started_at' => $table->start_time,
            'session_ended_at' => $snapshot['ended_at'],
            'duration_minutes' => $snapshot['duration_minutes'],
            'session_charge_name' => $this->resolveSessionChargeName($table),
            'session_charge_total' => $snapshot['session_charge_total'],
            'selected_package_name' => $table->selected_package_name,
            'selected_package_hours' => $table->selected_package_hours ?? 0,
            'selected_package_price' => $table->selected_package_price ?? 0,
            'locked_final' => true,
            'close_reason' => $closeReason,
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
    }

    private function buildLiveTableDraftSnapshot(Table $table, Carbon $endedAt): array
    {
        $expiredAt = $this->billingService->calculatePackageExpiredAt($table);
        if (
            $expiredAt &&
            $table->billing_mode?->value === 'package' &&
            $expiredAt->lessThan($endedAt)
        ) {
            return $this->buildFrozenExpiredPackageSnapshot($table, $expiredAt);
        }

        $bill = $this->billingService->calculateTableBill($table);

        return [
            'ended_at' => $endedAt,
            'duration_minutes' => $this->resolveDurationMinutes($table, $endedAt),
            'session_charge_total' => (int) ($bill['rental_cost'] ?? 0),
        ];
    }

    private function buildFrozenExpiredPackageSnapshot(Table $table, Carbon $endedAt): array
    {
        return [
            'ended_at' => $endedAt,
            'duration_minutes' => $this->billingService->calculatePackageIncludedMinutes($table),
            'session_charge_total' => $this->billingService->calculatePackageTotalPrice($table),
        ];
    }

    private function resolveDurationMinutes(Table $table, Carbon $endedAt): int
    {
        $startedAt = $table->start_time;
        if (!$startedAt) {
            return 0;
        }

        return max(
            0,
            intdiv($endedAt->getTimestamp() - $startedAt->getTimestamp(), 60),
        );
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
        ?Staff $staff,
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
            ->when($staff != null, fn ($collection) => $collection->push([
                'staff_id' => $staff->id,
                'staff_name' => $staff->name,
            ]))
            ->filter(fn (array $row) => !empty($row['staff_id']))
            ->unique('staff_id')
            ->values();

        foreach ($staffRows as $staffRow) {
            OpenBillInvolvedStaff::create([
                'tenant_id' => $draft->tenant_id,
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
            'tenant_id' => $draft->tenant_id,
            'open_bill_id' => $draft->id,
            'fulfillment_type' => FulfillmentType::DineIn,
            'table_id' => null,
            'table_name' => $table->name,
            'subtotal' => 0,
        ]);

        foreach ($items as $item) {
            $group->items()->create([
                'tenant_id' => $draft->tenant_id,
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
