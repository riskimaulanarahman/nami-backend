<?php

namespace App\Http\Controllers\Api;

use App\Enums\FulfillmentType;
use App\Enums\OpenBillStatus;
use App\Enums\TableStatus;
use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\OpenBill;
use App\Models\OpenBillGroup;
use App\Models\OpenBillInvolvedStaff;
use App\Models\Table;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Services\StockService;
use App\Models\BusinessSettings;
use App\Http\Resources\TableResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class OpenBillController extends Controller
{
    public function __construct(
        private OrderService $orderService,
        private BillingService $billingService,
        private StockService $stockService,
    ) {}

    public function index()
    {
        return response()->json([
            'data' => OpenBill::where('status', OpenBillStatus::Open)
                ->with(['groups.items.menuItem', 'involvedStaff', 'member'])
                ->orderByDesc('created_at')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'table_id' => ['nullable', Rule::exists('tables', 'id')->where('tenant_id', $tenantId)],
            'customer_name' => 'nullable|string|max:255',
            'waiting_list_entry_id' => ['nullable', Rule::exists('waiting_list_entries', 'id')->where('tenant_id', $tenantId)],
        ]);

        $staff = $request->user();
        $shift = $request->input('active_shift');
        $code = $this->generateOpenBillCode();
        $table = null;

        if (!empty($data['table_id'])) {
            $table = Table::find($data['table_id']);
            if ($table?->active_open_bill_id) {
                return response()->json([
                    'message' => 'Meja sudah terhubung dengan open bill lain.',
                ], 422);
            }
        }

        $bill = OpenBill::create([
            'code' => $code,
            'customer_name' => $data['customer_name'] ?? '',
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

        // Create dine-in group if table assigned
        if ($table) {
            OpenBillGroup::create([
                'open_bill_id' => $bill->id,
                'fulfillment_type' => FulfillmentType::DineIn,
                'table_id' => $table->id,
                'table_name' => $table->name,
            ]);
            $table->update(['active_open_bill_id' => $bill->id]);
        }

        return response()->json(['data' => $bill->load(['groups.items.menuItem', 'involvedStaff'])], 201);
    }

    public function show(OpenBill $openBill)
    {
        return response()->json(['data' => $openBill->load(['groups.items.menuItem', 'involvedStaff', 'member'])]);
    }

    public function receipt(OpenBill $openBill)
    {
        $openBill->loadMissing(['groups.items.menuItem', 'groups.table', 'involvedStaff', 'member']);

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
                'draft_label' => 'BELUM LUNAS',
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
                            'menu_item_emoji' => $item->menuItem?->emoji ?? '',
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'subtotal' => $item->unit_price * $item->quantity,
                            'added_at' => $item->added_at,
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
        $data = $request->validate([
            'customer_name' => 'sometimes|string|max:255',
            'points_to_redeem' => 'sometimes|integer|min:0',
        ]);
        $openBill->update($data);
        return response()->json(['data' => $openBill->fresh()]);
    }

    public function destroy(OpenBill $openBill)
    {
        $openBill->loadMissing('groups.items.menuItem');

        // Unlink tables
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

        $openBill->groups()->each(fn ($g) => $g->items()->delete());
        $openBill->groups()->delete();
        $openBill->involvedStaff()->delete();
        $openBill->delete();

        return response()->json(['message' => 'Open bill dihapus.']);
    }

    public function assignTable(Request $request, OpenBill $openBill)
    {
        $tenantId = $request->user()?->tenant_id;
        $data = $request->validate([
            'table_id' => ['required', Rule::exists('tables', 'id')->where('tenant_id', $tenantId)],
        ]);
        $table = Table::findOrFail($data['table_id']);
        if ($table->active_open_bill_id && $table->active_open_bill_id !== $openBill->id) {
            return response()->json([
                'message' => 'Meja sudah terhubung dengan open bill lain.',
            ], 422);
        }

        // Find or create dine-in group
        $group = $openBill->groups()->where('fulfillment_type', FulfillmentType::DineIn)->first();
        if ($group) {
            // Unlink previous table
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

        return response()->json(['data' => $openBill->fresh()->load('groups.items.menuItem')]);
    }

    public function addItem(Request $request, OpenBill $openBill)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'fulfillment_type' => 'required|in:dine-in,takeaway',
            'menu_item_id' => ['required', Rule::exists('menu_items', 'id')->where('tenant_id', $tenantId)],
        ]);

        $menuItem = MenuItem::findOrFail($data['menu_item_id']);
        $fulfillmentType = FulfillmentType::from($data['fulfillment_type']);
        $staff = $request->user();

        // Find or create group
        $group = $openBill->groups()->where('fulfillment_type', $fulfillmentType)->first();
        if (!$group) {
            $group = OpenBillGroup::create([
                'open_bill_id' => $openBill->id,
                'fulfillment_type' => $fulfillmentType,
            ]);
        }

        // Find or create item in group
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

        // Track staff
        OpenBillInvolvedStaff::firstOrCreate(
            ['open_bill_id' => $openBill->id, 'staff_id' => $staff->id],
            ['staff_name' => $staff->name]
        );

        return response()->json(['data' => $openBill->fresh()->load('groups.items.menuItem')]);
    }

    public function removeItem(Request $request, OpenBill $openBill)
    {
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

        return response()->json(['data' => $openBill->fresh()->load('groups.items.menuItem')]);
    }

    public function updateItem(Request $request, OpenBill $openBill)
    {
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
                return response()->json(['message' => 'Item open bill tidak ditemukan.'], 404);
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

        return response()->json(['data' => $openBill->fresh()->load('groups.items.menuItem')]);
    }

    public function attachMember(Request $request, OpenBill $openBill)
    {
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
        ]);

        $staff = $request->user();
        $shift = $request->input('active_shift');

        $order = $this->orderService->checkoutOpenBill(
            $openBill, $staff, $shift,
            $data['payment_method_id'] ?? null,
            $data['payment_method_name'] ?? null,
            $data['payment_reference'] ?? null,
        );

        return response()->json(['data' => $order->load(['groups.items', 'involvedStaff'])]);
    }

    public function totals(OpenBill $openBill)
    {
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
}
