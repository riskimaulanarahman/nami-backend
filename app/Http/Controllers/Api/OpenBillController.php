<?php

namespace App\Http\Controllers\Api;

use App\Enums\FulfillmentType;
use App\Enums\OpenBillStatus;
use App\Http\Controllers\Controller;
use App\Models\BusinessSettings;
use App\Models\MenuItem;
use App\Models\OpenBill;
use App\Models\OpenBillGroup;
use App\Models\OpenBillGroupItem;
use App\Models\OpenBillInvolvedStaff;
use App\Models\Table;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OpenBillController extends Controller
{
    public function __construct(
        private OrderService $orderService,
        private BillingService $billingService,
        private StockService $stockService,
    ) {}

    public function index()
    {
        $statuses = $this->resolveRequestedStatuses(request());

        return response()->json([
            'data' => OpenBill::whereIn(
                'status',
                array_map(fn (OpenBillStatus $status) => $status->value, $statuses),
            )
                ->with(['groups.items.menuItem', 'involvedStaff', 'member'])
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'table_id' => ['nullable', Rule::exists('tables', 'id')->where('tenant_id', $tenantId)],
            'member_id' => ['nullable', Rule::exists('members', 'id')->where('tenant_id', $tenantId)],
            'customer_name' => 'nullable|string|max:255',
            'points_to_redeem' => 'nullable|integer|min:0',
            'fulfillment_type' => 'nullable|in:dine-in,takeaway',
            'waiting_list_entry_id' => ['nullable', Rule::exists('waiting_list_entries', 'id')->where('tenant_id', $tenantId)],
            'groups' => 'nullable|array',
            'groups.*.fulfillment_type' => 'required_with:groups|in:dine-in,takeaway',
            'groups.*.table_id' => ['nullable', Rule::exists('tables', 'id')->where('tenant_id', $tenantId)],
            'groups.*.table_name' => 'nullable|string|max:255',
            'groups.*.items' => 'nullable|array',
            'groups.*.items.*.menu_item_id' => ['required_with:groups.*.items', Rule::exists('menu_items', 'id')->where('tenant_id', $tenantId)],
            'groups.*.items.*.quantity' => 'nullable|integer|min:1',
            'groups.*.items.*.note' => 'nullable|string|max:255',
        ]);

        $staff = $request->user();
        $shift = $request->input('active_shift');
        $code = $this->generateOpenBillCode();
        $groupPayloads = $this->normalizeStoreGroups($data);
        $tableIds = collect($groupPayloads)
            ->pluck('table_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (!empty($tableIds)) {
            $tables = Table::query()->whereIn('id', $tableIds)->get()->keyBy('id');
            foreach ($tableIds as $tableId) {
                $table = $tables[$tableId] ?? null;
                if ($table?->active_open_bill_id) {
                    return response()->json([
                        'message' => 'Table is already linked to another draft.',
                    ], 422);
                }
            }
        }

        $bill = DB::transaction(function () use ($code, $data, $groupPayloads, $shift, $staff) {
            $bill = OpenBill::create([
                'code' => $code,
                'customer_name' => $data['customer_name'] ?? '',
                'member_id' => $data['member_id'] ?? null,
                'points_to_redeem' => $data['points_to_redeem'] ?? 0,
                'status' => OpenBillStatus::Open,
                'waiting_list_entry_id' => $data['waiting_list_entry_id'] ?? null,
                'origin_cashier_shift_id' => $shift->id,
                'origin_staff_id' => $staff->id,
                'origin_staff_name' => $staff->name,
            ]);

            OpenBillInvolvedStaff::create([
                'open_bill_id' => $bill->id,
                'staff_id' => $staff->id,
                'staff_name' => $staff->name,
            ]);

            foreach ($groupPayloads as $groupPayload) {
                $table = !empty($groupPayload['table_id'])
                    ? Table::find($groupPayload['table_id'])
                    : null;

                $group = OpenBillGroup::create([
                    'open_bill_id' => $bill->id,
                    'fulfillment_type' => FulfillmentType::from($groupPayload['fulfillment_type']),
                    'table_id' => $table?->id,
                    'table_name' => $table?->name ?? ($groupPayload['table_name'] ?? null),
                    'subtotal' => 0,
                ]);

                foreach ($groupPayload['items'] as $itemPayload) {
                    $menuItem = MenuItem::findOrFail($itemPayload['menu_item_id']);
                    $quantity = max(1, (int) ($itemPayload['quantity'] ?? 1));

                    OpenBillGroupItem::create([
                        'open_bill_group_id' => $group->id,
                        'menu_item_id' => $menuItem->id,
                        'quantity' => $quantity,
                        'unit_price' => $menuItem->price,
                        'added_at' => now(),
                        'note' => $itemPayload['note'] ?? null,
                    ]);

                    $this->stockService->deductForMenuItem($menuItem, $quantity);
                }

                $group->recalculateSubtotal();

                if ($table) {
                    $table->update(['active_open_bill_id' => $bill->id]);
                }
            }

            return $bill;
        });

        return response()->json(['data' => $bill->load(['groups.items.menuItem', 'involvedStaff', 'member'])], 201);
    }

    public function show(OpenBill $openBill)
    {
        return response()->json(['data' => $openBill->load(['groups.items.menuItem', 'involvedStaff', 'member'])]);
    }

    public function receipt(OpenBill $openBill)
    {
        $openBill->loadMissing(['groups.items.menuItem', 'groups.table', 'involvedStaff', 'member']);

        if ($openBill->isFrozenTableDraft()) {
            return response()->json([
                'data' => $this->buildFrozenDraftReceiptPayload($openBill),
            ]);
        }

        $settings = BusinessSettings::first();
        $taxPercent = (int) ($settings?->tax_percent ?? 0);
        $memberBalance = (int) ($openBill->member?->points_balance ?? 0);

        $subtotal = $openBill->groups->sum(
            fn (OpenBillGroup $group) => $group->items->sum(fn ($item) => $item->unit_price * $item->quantity)
        );

        $totals = $this->billingService->calculateOpenBillTotals(
            $subtotal,
            $openBill->points_to_redeem,
            $memberBalance,
            $taxPercent,
        );

        $hasDineIn = $openBill->groups->contains(fn (OpenBillGroup $group) => $group->fulfillment_type === FulfillmentType::DineIn && $group->items->isNotEmpty());
        $hasTakeaway = $openBill->groups->contains(fn (OpenBillGroup $group) => $group->fulfillment_type === FulfillmentType::Takeaway && $group->items->isNotEmpty());

        $billType = $hasDineIn && $hasTakeaway
            ? 'mixed'
            : ($hasDineIn ? 'dine-in' : ($hasTakeaway ? 'takeaway' : 'open-bill'));

        $primaryGroup = $openBill->groups
            ->filter(fn (OpenBillGroup $group) => $group->items->isNotEmpty())
            ->sortBy(fn (OpenBillGroup $group) => $group->fulfillment_type === FulfillmentType::DineIn ? 0 : 1)
            ->first();

        if (!$primaryGroup) {
            $primaryGroup = $openBill->groups
                ->sortBy(fn (OpenBillGroup $group) => $group->fulfillment_type === FulfillmentType::DineIn ? 0 : 1)
                ->first();
        }

        $servedBy = $openBill->involvedStaff
            ->pluck('staff_name')
            ->filter()
            ->unique()
            ->implode(' → ');

        return response()->json([
            'data' => [
                'kind' => 'draft-open-bill',
                'id' => $openBill->id,
                'code' => $openBill->code,
                'status' => $openBill->status->value,
                'table_name' => $primaryGroup?->table_name ?? ($openBill->customer_name ?: $openBill->code),
                'table_type' => $primaryGroup?->table?->type?->value ?? null,
                'session_type' => 'cafe',
                'bill_type' => $billType,
                'billiard_billing_mode' => null,
                'selected_package_name' => null,
                'selected_package_hours' => 0,
                'duration_minutes' => max(0, $openBill->created_at?->diffInMinutes(now()) ?? 0),
                'payment_method_name' => null,
                'payment_reference' => null,
                'served_by' => $servedBy,
                'member_name' => $openBill->member?->name,
                'member_code' => $openBill->member?->code,
                'customer_name' => $openBill->customer_name,
                'points_earned' => 0,
                'points_redeemed' => $totals['points_redeemed'],
                'start_time' => $openBill->created_at,
                'end_time' => now(),
                'created_at' => $openBill->created_at,
                'updated_at' => $openBill->updated_at,
                'draft_label' => 'UNPAID',
                'close_reason' => $openBill->close_reason?->value,
                'groups' => $openBill->groups
                    ->sortBy(fn (OpenBillGroup $group) => $group->fulfillment_type === FulfillmentType::DineIn ? 0 : 1)
                    ->map(fn (OpenBillGroup $group) => [
                        'id' => $group->id,
                        'fulfillment_type' => $group->fulfillment_type->value,
                        'table_id' => $group->table_id,
                        'table_name' => $group->table_name,
                        'subtotal' => $group->subtotal,
                        'items' => $group->items->map(fn ($item) => [
                            'menu_item_id' => $item->menu_item_id,
                            'menu_item_name' => $item->menuItem?->name ?? 'Unknown',
                            'menu_item_emoji' => '',
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'subtotal' => $item->unit_price * $item->quantity,
                            'added_at' => $item->added_at,
                            'note' => $item->note,
                        ])->values(),
                    ])->values(),
                'totals' => [
                    'rental_cost' => 0,
                    'order_total' => $subtotal,
                    'redeem_amount' => $totals['redeem_amount'],
                    'tax_percent' => $taxPercent,
                    'tax_amount' => $totals['tax'],
                    'grand_total_before_tax' => $totals['total'] - $totals['tax'],
                    'final_total' => $totals['total'],
                ],
            ],
        ]);
    }

    public function update(Request $request, OpenBill $openBill)
    {
        if ($response = $this->ensureMutable($openBill)) {
            return $response;
        }

        $tenantId = $request->user()?->tenant_id;
        $data = $request->validate([
            'customer_name' => 'sometimes|string|max:255',
            'member_id' => ['sometimes', 'nullable', Rule::exists('members', 'id')->where('tenant_id', $tenantId)],
            'points_to_redeem' => 'sometimes|integer|min:0',
        ]);
        $openBill->update($data);
        return response()->json(['data' => $openBill->fresh()->load('member')]);
    }

    public function destroy(Request $request, OpenBill $openBill)
    {
        if ($response = $this->ensureMutable($openBill)) {
            return $response;
        }

        $openBill->loadMissing('groups.items.menuItem');
        $totalAmount = $openBill->draftTotalAmount();
        $data = Validator::make($this->resolveDeletePayload($request), [
            'delete_reason' => [
                $totalAmount > 0 ? 'required' : 'nullable',
                'string',
                'max:500',
            ],
        ])->validate();

        $tableIds = $openBill->groups()->whereNotNull('table_id')->pluck('table_id');
        if ($tableIds->isNotEmpty()) {
            Table::whereIn('id', $tableIds)->update(['active_open_bill_id' => null]);
        }

        foreach ($openBill->groups as $group) {
            foreach ($group->items as $item) {
                if ($item->menuItem) {
                    $this->stockService->restockForMenuItem($item->menuItem, $item->quantity);
                }
            }
        }

        $openBill->update([
            'delete_reason' => $data['delete_reason'] ?? null,
            'deleted_by_staff_id' => $request->user()?->id,
            'deleted_by_staff_name' => $request->user()?->name,
        ]);
        $openBill->delete();

        return response()->json(['message' => 'Draft deleted.']);
    }

    public function assignTable(Request $request, OpenBill $openBill)
    {
        if ($response = $this->ensureMutable($openBill)) {
            return $response;
        }

        $tenantId = $request->user()?->tenant_id;
        $data = $request->validate([
            'table_id' => ['required', Rule::exists('tables', 'id')->where('tenant_id', $tenantId)],
        ]);
        $table = Table::findOrFail($data['table_id']);
        if ($table->active_open_bill_id && $table->active_open_bill_id !== $openBill->id) {
            return response()->json([
                'message' => 'Table is already linked to another draft.',
            ], 422);
        }

        $group = $openBill->groups()->where('fulfillment_type', FulfillmentType::DineIn)->first();
        if ($group) {
            if ($group->table_id && $group->table_id !== $table->id) {
                Table::where('id', $group->table_id)->update(['active_open_bill_id' => null]);
            }
            $group->update(['table_id' => $table->id, 'table_name' => $table->name]);
        } else {
            OpenBillGroup::create([
                'open_bill_id' => $openBill->id,
                'fulfillment_type' => FulfillmentType::DineIn,
                'table_id' => $table->id,
                'table_name' => $table->name,
            ]);
        }

        $table->update(['active_open_bill_id' => $openBill->id]);

        return response()->json(['data' => $openBill->fresh()->load('groups.items.menuItem', 'member')]);
    }

    public function addItem(Request $request, OpenBill $openBill)
    {
        if ($response = $this->ensureMutable($openBill)) {
            return $response;
        }

        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'fulfillment_type' => 'required|in:dine-in,takeaway',
            'menu_item_id' => ['required', Rule::exists('menu_items', 'id')->where('tenant_id', $tenantId)],
        ]);

        $menuItem = MenuItem::findOrFail($data['menu_item_id']);
        $fulfillmentType = FulfillmentType::from($data['fulfillment_type']);
        $staff = $request->user();

        $group = $openBill->groups()->where('fulfillment_type', $fulfillmentType)->first();
        if (!$group) {
            $group = OpenBillGroup::create([
                'open_bill_id' => $openBill->id,
                'fulfillment_type' => $fulfillmentType,
            ]);
        }

        $existing = $group->items()->where('menu_item_id', $menuItem->id)->first();
        if ($existing) {
            $existing->increment('quantity');
        } else {
            $group->items()->create([
                'menu_item_id' => $menuItem->id,
                'quantity' => 1,
                'unit_price' => $menuItem->price,
                'added_at' => now(),
            ]);
        }

        $group->recalculateSubtotal();
        $this->stockService->deductForMenuItem($menuItem);

        OpenBillInvolvedStaff::firstOrCreate(
            ['open_bill_id' => $openBill->id, 'staff_id' => $staff->id],
            ['staff_name' => $staff->name]
        );

        return response()->json(['data' => $openBill->fresh()->load('groups.items.menuItem', 'member')]);
    }

    public function removeItem(Request $request, OpenBill $openBill)
    {
        if ($response = $this->ensureMutable($openBill)) {
            return $response;
        }

        $data = $request->validate([
            'fulfillment_type' => 'required|in:dine-in,takeaway',
            'menu_item_id' => 'required|string',
        ]);

        $group = $openBill->groups()->where('fulfillment_type', $data['fulfillment_type'])->first();
        $item = $group?->items()->with('menuItem')->where('menu_item_id', $data['menu_item_id'])->first();
        if ($item?->menuItem) {
            $this->stockService->restockForMenuItem($item->menuItem, $item->quantity);
        }
        $item?->delete();
        $group?->recalculateSubtotal();

        return response()->json(['data' => $openBill->fresh()->load('groups.items.menuItem', 'member')]);
    }

    public function updateItem(Request $request, OpenBill $openBill)
    {
        if ($response = $this->ensureMutable($openBill)) {
            return $response;
        }

        $data = $request->validate([
            'fulfillment_type' => 'required|in:dine-in,takeaway',
            'menu_item_id' => 'required|string',
            'quantity' => 'required|integer|min:0',
            'note' => 'nullable|string|max:255',
        ]);

        $group = $openBill->groups()->where('fulfillment_type', $data['fulfillment_type'])->first();
        if ($group) {
            $item = $group->items()->with('menuItem')->where('menu_item_id', $data['menu_item_id'])->first();
            if (!$item) {
                return response()->json(['message' => 'Draft item not found.'], 404);
            }

            if ($data['quantity'] === 0) {
                if ($item->menuItem) {
                    $this->stockService->restockForMenuItem($item->menuItem, $item->quantity);
                }
                $item->delete();
            } else {
                $delta = $data['quantity'] - $item->quantity;
                if ($delta > 0 && $item->menuItem) {
                    $this->stockService->deductForMenuItem($item->menuItem, $delta);
                } elseif ($delta < 0 && $item->menuItem) {
                    $this->stockService->restockForMenuItem($item->menuItem, abs($delta));
                }

                $update = ['quantity' => $data['quantity']];
                if (array_key_exists('note', $data)) {
                    $update['note'] = $data['note'] ?: null;
                }
                $item->update($update);
            }
            $group->recalculateSubtotal();
        }

        return response()->json(['data' => $openBill->fresh()->load('groups.items.menuItem', 'member')]);
    }

    public function attachMember(Request $request, OpenBill $openBill)
    {
        if ($response = $this->ensureMutable($openBill)) {
            return $response;
        }

        $tenantId = $request->user()?->tenant_id;
        $data = $request->validate([
            'member_id' => ['nullable', Rule::exists('members', 'id')->where('tenant_id', $tenantId)],
        ]);
        $openBill->update([
            'member_id' => $data['member_id'],
            'points_to_redeem' => $data['member_id'] ? $openBill->points_to_redeem : 0,
        ]);
        return response()->json(['data' => $openBill->fresh()->load('member')]);
    }

    public function checkout(Request $request, OpenBill $openBill)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'payment_method_id' => ['nullable', Rule::exists('payment_options', 'id')->where('tenant_id', $tenantId)],
            'payment_method_name' => 'nullable|string',
            'payment_reference' => 'nullable|string',
            'cash_received' => 'nullable|integer|min:0',
        ]);

        $staff = $request->user();
        $shift = $request->input('active_shift');

        try {
            $order = $this->orderService->checkoutOpenBill(
                $openBill,
                $staff,
                $shift,
                $data['payment_method_id'] ?? null,
                $data['payment_method_name'] ?? null,
                $data['payment_reference'] ?? null,
                $data['cash_received'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $order->load(['groups.items', 'involvedStaff'])]);
    }

    public function totals(OpenBill $openBill)
    {
        if ($openBill->isFrozenTableDraft()) {
            $orderTotal = $openBill->groups->sum(
                fn (OpenBillGroup $group) => $group->items->sum(fn ($item) => $item->unit_price * $item->quantity)
            );
            $subtotal = $orderTotal + (int) ($openBill->session_charge_total ?? 0);

            return response()->json(['data' => [
                'subtotal' => $subtotal,
                'points_redeemed' => 0,
                'redeem_amount' => 0,
                'tax' => 0,
                'total' => $subtotal,
            ]]);
        }

        $openBill->loadMissing(['groups.items', 'member']);
        $settings = BusinessSettings::first();

        $subtotal = 0;
        foreach ($openBill->groups as $group) {
            foreach ($group->items as $item) {
                $subtotal += $item->unit_price * $item->quantity;
            }
        }

        $totals = $this->billingService->calculateOpenBillTotals(
            $subtotal,
            $openBill->points_to_redeem,
            $openBill->member?->points_balance ?? 0,
            $settings?->tax_percent ?? 0,
        );

        return response()->json(['data' => $totals]);
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

    private function normalizeStoreGroups(array $data): array
    {
        $groups = collect($data['groups'] ?? [])
            ->map(fn (array $group): array => [
                'fulfillment_type' => $group['fulfillment_type'],
                'table_id' => $group['table_id'] ?? null,
                'table_name' => $group['table_name'] ?? null,
                'items' => collect($group['items'] ?? [])
                    ->map(fn (array $item): array => [
                        'menu_item_id' => $item['menu_item_id'],
                        'quantity' => $item['quantity'] ?? 1,
                        'note' => $item['note'] ?? null,
                    ])
                    ->all(),
            ])
            ->all();

        if (!empty($groups)) {
            return $groups;
        }

        if (!empty($data['table_id'])) {
            return [[
                'fulfillment_type' => 'dine-in',
                'table_id' => $data['table_id'],
                'table_name' => null,
                'items' => [],
            ]];
        }

        if (!empty($data['fulfillment_type'])) {
            return [[
                'fulfillment_type' => $data['fulfillment_type'],
                'table_id' => null,
                'table_name' => null,
                'items' => [],
            ]];
        }

        return [];
    }

    private function resolveRequestedStatuses(Request $request): array
    {
        $rawStatuses = collect(explode(',', (string) $request->query('status', 'open')))
            ->map(fn (string $status) => trim(strtolower($status)))
            ->filter()
            ->map(fn (string $status) => OpenBillStatus::tryFrom($status))
            ->filter()
            ->values()
            ->all();

        if (!empty($rawStatuses)) {
            return $rawStatuses;
        }

        return [OpenBillStatus::Open];
    }

    private function ensureMutable(OpenBill $openBill): ?JsonResponse
    {
        if (!$openBill->isFrozenTableDraft()) {
            return null;
        }

        return response()->json([
            'message' => 'Frozen draft hanya bisa diprint atau dibayar.',
        ], 422);
    }

    private function buildFrozenDraftReceiptPayload(OpenBill $openBill): array
    {
        $servedBy = $openBill->involvedStaff
            ->pluck('staff_name')
            ->filter()
            ->unique()
            ->implode(' → ');

        $orderTotal = $openBill->groups->sum(
            fn (OpenBillGroup $group) => $group->items->sum(fn ($item) => $item->unit_price * $item->quantity)
        );
        $finalTotal = $orderTotal + (int) ($openBill->session_charge_total ?? 0);

        return [
            'kind' => 'draft-open-bill',
            'id' => $openBill->id,
            'code' => $openBill->code,
            'status' => $openBill->status->value,
            'table_name' => $openBill->source_table_name ?? ($openBill->customer_name ?: $openBill->code),
            'table_type' => $openBill->source_table_type?->value,
            'session_type' => $openBill->session_type?->value ?? 'billiard',
            'bill_type' => $openBill->selected_package_price > 0 ? 'package' : 'billiard',
            'billiard_billing_mode' => $openBill->billing_mode?->value,
            'selected_package_name' => $openBill->selected_package_name,
            'selected_package_hours' => (int) ($openBill->selected_package_hours ?? 0),
            'selected_package_price' => (int) ($openBill->selected_package_price ?? 0),
            'duration_minutes' => (int) ($openBill->duration_minutes ?? 0),
            'payment_method_name' => null,
            'payment_reference' => null,
            'served_by' => $servedBy,
            'member_name' => $openBill->member?->name,
            'member_code' => $openBill->member?->code,
            'customer_name' => $openBill->customer_name,
            'points_earned' => 0,
            'points_redeemed' => 0,
            'start_time' => $openBill->session_started_at,
            'end_time' => $openBill->session_ended_at,
            'created_at' => $openBill->created_at,
            'updated_at' => $openBill->updated_at,
            'draft_label' => 'UNPAID',
            'source_table_id' => $openBill->source_table_id,
            'source_table_name' => $openBill->source_table_name,
            'locked_final' => $openBill->locked_final,
            'close_reason' => $openBill->close_reason?->value,
            'session_charge_total' => (int) ($openBill->session_charge_total ?? 0),
            'session_charge_name' => $openBill->session_charge_name,
            'groups' => $openBill->groups
                ->sortBy(fn (OpenBillGroup $group) => $group->fulfillment_type === FulfillmentType::DineIn ? 0 : 1)
                ->map(fn (OpenBillGroup $group) => [
                    'id' => $group->id,
                    'fulfillment_type' => $group->fulfillment_type->value,
                    'table_id' => $group->table_id,
                    'table_name' => $group->table_name,
                    'subtotal' => $group->subtotal,
                    'items' => $group->items->map(fn ($item) => [
                        'menu_item_id' => $item->menu_item_id,
                        'menu_item_name' => $item->menuItem?->name ?? 'Unknown',
                        'menu_item_emoji' => '',
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'subtotal' => $item->unit_price * $item->quantity,
                        'added_at' => $item->added_at,
                        'note' => $item->note,
                    ])->values(),
                ])->values(),
            'totals' => [
                'rental_cost' => (int) ($openBill->session_charge_total ?? 0),
                'order_total' => $orderTotal,
                'redeem_amount' => 0,
                'tax_percent' => 0,
                'tax_amount' => 0,
                'grand_total_before_tax' => $finalTotal,
                'final_total' => $finalTotal,
            ],
        ];
    }

    private function resolveDeletePayload(Request $request): array
    {
        $payload = $request->all();
        if (!is_array($payload)) {
            return [];
        }

        return $payload;
    }
}
