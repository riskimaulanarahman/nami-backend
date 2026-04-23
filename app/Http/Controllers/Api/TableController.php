<?php

namespace App\Http\Controllers\Api;

use App\Enums\FulfillmentType;
use App\Enums\OpenBillStatus;
use App\Enums\SessionType;
use App\Enums\TableStatus;
use App\Http\Controllers\Controller;
use App\Models\BilliardPackage;
use App\Models\MenuItem;
use App\Models\OpenBill;
use App\Models\OpenBillGroup;
use App\Models\OpenBillInvolvedStaff;
use App\Models\Table;
use App\Models\TableInvolvedStaff;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Services\StockService;
use App\Http\Requests\Table\StartSessionRequest;
use App\Http\Resources\TableResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TableController extends Controller
{
    public function __construct(
        private OrderService $orderService,
        private BillingService $billingService,
        private StockService $stockService,
    ) {}

    public function index()
    {
        $tables = Table::with(['layoutPosition', 'involvedStaff', 'orderItems.menuItem'])->get();
        return TableResource::collection($tables);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:standard,vip',
            'hourly_rate' => 'required|integer|min:0',
        ]);

        $table = Table::create($data);
        return response()->json(['data' => $table->load('layoutPosition')], 201);
    }

    public function show(Table $table)
    {
        return new TableResource($table->load(['layoutPosition', 'involvedStaff', 'orderItems.menuItem']));
    }

    public function update(Request $request, Table $table)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:standard,vip',
            'hourly_rate' => 'sometimes|integer|min:0',
        ]);
        $table->update($data);
        return response()->json(['data' => $table->fresh()]);
    }

    public function destroy(Table $table)
    {
        $table->delete();
        return response()->json(['message' => 'Meja dihapus.']);
    }

    public function startSession(StartSessionRequest $request, Table $table)
    {
        if ($table->status !== TableStatus::Available && $table->status !== TableStatus::Reserved) {
            return response()->json(['message' => 'Meja tidak tersedia.'], 422);
        }

        $data = $request->validated();
        $staff = $request->user();
        $shift = $request->input('active_shift');
        $package = isset($data['package_id']) ? BilliardPackage::find($data['package_id']) : null;

        $table->update([
            'status' => TableStatus::Occupied,
            'start_time' => now(),
            'session_type' => $data['session_type'],
            'billing_mode' => $data['session_type'] === 'billiard' ? ($data['billing_mode'] ?? 'open-bill') : null,
            'selected_package_id' => $package?->id,
            'selected_package_name' => $package?->name,
            'selected_package_hours' => $package?->duration_hours ?? 0,
            'selected_package_price' => $package?->price ?? 0,
            'package_minutes_total' => ($package?->duration_hours ?? 0) * 60,
            'package_total_price' => $package?->price ?? 0,
            'package_reminder_shown_at' => null,
            'origin_cashier_shift_id' => $shift->id,
            'origin_staff_id' => $staff->id,
            'origin_staff_name' => $staff->name,
        ]);

        TableInvolvedStaff::firstOrCreate(
            ['table_id' => $table->id, 'staff_id' => $staff->id],
            ['staff_name' => $staff->name]
        );

        return new TableResource($table->fresh()->load(['involvedStaff', 'orderItems']));
    }

    public function acknowledgePackageExpiry(Table $table)
    {
        if ($table->status !== TableStatus::Occupied) {
            return response()->json(['message' => 'Meja tidak sedang aktif.'], 422);
        }

        $table->update([
            'package_reminder_shown_at' => now(),
        ]);

        return new TableResource($table->fresh()->load(['involvedStaff', 'orderItems.menuItem']));
    }

    public function extendPackage(Request $request, Table $table)
    {
        $tenantId = $request->user()?->tenant_id;
        $data = $request->validate([
            'package_id' => ['required', Rule::exists('billiard_packages', 'id')->where('tenant_id', $tenantId)],
        ]);

        if ($table->status !== TableStatus::Occupied) {
            return response()->json(['message' => 'Meja tidak sedang aktif.'], 422);
        }

        if ($table->billing_mode?->value !== 'package') {
            return response()->json(['message' => 'Perpanjangan paket hanya tersedia untuk sesi paket.'], 422);
        }

        $package = BilliardPackage::findOrFail($data['package_id']);
        $currentMinutes = $this->billingService->calculatePackageIncludedMinutes($table);
        $currentPrice = $this->billingService->calculatePackageTotalPrice($table);
        $nextMinutes = $currentMinutes + ($package->duration_hours * 60);
        $nextPrice = $currentPrice + $package->price;

        $table->update([
            'selected_package_id' => $package->id,
            'selected_package_name' => $package->name,
            'selected_package_hours' => intdiv($nextMinutes, 60),
            'selected_package_price' => $nextPrice,
            'package_minutes_total' => $nextMinutes,
            'package_total_price' => $nextPrice,
            'package_reminder_shown_at' => null,
        ]);

        return new TableResource($table->fresh()->load(['involvedStaff', 'orderItems.menuItem']));
    }

    public function convertPackageToOpenBill(Table $table)
    {
        if ($table->status !== TableStatus::Occupied) {
            return response()->json(['message' => 'Meja tidak sedang aktif.'], 422);
        }

        if ($table->billing_mode?->value !== 'package') {
            return response()->json(['message' => 'Sesi ini tidak memakai paket.'], 422);
        }

        $table->update([
            'billing_mode' => 'open-bill',
            'package_reminder_shown_at' => null,
        ]);

        return new TableResource($table->fresh()->load(['involvedStaff', 'orderItems.menuItem']));
    }

    public function endSession(Request $request, Table $table)
    {
        if ($table->status !== TableStatus::Occupied) {
            return response()->json(['message' => 'Meja tidak sedang aktif.'], 422);
        }
        $table->resetSession();
        return response()->json(['data' => $table->fresh()]);
    }

    public function checkout(Request $request, Table $table)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'payment_method_id' => ['nullable', Rule::exists('payment_options', 'id')->where('tenant_id', $tenantId)],
            'payment_method_name' => 'nullable|string',
            'payment_reference' => 'nullable|string',
            'cash_received' => 'nullable|integer|min:0',
        ]);

        if ($table->status !== TableStatus::Occupied) {
            return response()->json(['message' => 'Meja tidak sedang aktif.'], 422);
        }

        $staff = $request->user();
        $shift = $request->input('active_shift');

        try {
            $order = $this->orderService->checkoutTableSession(
                $table, $staff, $shift,
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

    public function addOrder(Request $request, Table $table)
    {
        $tenantId = $request->user()?->tenant_id;
        $data = $request->validate([
            'menu_item_id' => ['required', Rule::exists('menu_items', 'id')->where('tenant_id', $tenantId)],
        ]);

        if ($table->status !== TableStatus::Occupied) {
            return response()->json(['message' => 'Meja tidak sedang aktif.'], 422);
        }

        $menuItem = MenuItem::findOrFail($data['menu_item_id']);
        $staff = $request->user();

        $this->orderService->addOrderToTable($table, $menuItem, $staff);

        return response()->json(['data' => $table->fresh()->load('orderItems.menuItem')]);
    }

    public function appendDraftOrders(Request $request, Table $table)
    {
        $tenantId = $request->user()?->tenant_id;
        $data = $request->validate([
            'customer_name' => 'nullable|string|max:255',
            'groups' => 'required|array|min:1',
            'groups.*.fulfillment_type' => 'required|in:dine-in,takeaway',
            'groups.*.items' => 'required|array|min:1',
            'groups.*.items.*.menu_item_id' => ['required', Rule::exists('menu_items', 'id')->where('tenant_id', $tenantId)],
            'groups.*.items.*.quantity' => 'required|integer|min:1',
            'groups.*.items.*.note' => 'nullable|string|max:255',
        ]);

        if ($table->status !== TableStatus::Occupied) {
            return response()->json(['message' => 'Meja tidak sedang aktif.'], 422);
        }

        $staff = $request->user();
        $shift = $request->input('active_shift');

        $openBill = DB::transaction(function () use ($table, $data, $shift, $staff) {
            $openBill = $table->active_open_bill_id
                ? OpenBill::query()->with('groups.items.menuItem')->find($table->active_open_bill_id)
                : null;

            if (!$openBill) {
                $openBill = OpenBill::create([
                    'code' => $this->generateOpenBillCode(),
                    'customer_name' => $data['customer_name'] ?? '',
                    'points_to_redeem' => 0,
                    'status' => OpenBillStatus::Open,
                    'origin_cashier_shift_id' => $shift->id,
                    'origin_staff_id' => $staff->id,
                    'origin_staff_name' => $staff->name,
                ]);

                OpenBillInvolvedStaff::create([
                    'open_bill_id' => $openBill->id,
                    'staff_id' => $staff->id,
                    'staff_name' => $staff->name,
                ]);
            } elseif (!empty($data['customer_name'])) {
                $openBill->update(['customer_name' => $data['customer_name']]);
            }

            foreach ($data['groups'] as $groupPayload) {
                $fulfillmentType = FulfillmentType::from($groupPayload['fulfillment_type']);
                $group = $openBill->groups()
                    ->where('fulfillment_type', $fulfillmentType)
                    ->when(
                        $fulfillmentType === FulfillmentType::DineIn,
                        fn ($query) => $query->where('table_id', $table->id),
                    )
                    ->first();

                if (!$group) {
                    $group = OpenBillGroup::create([
                        'open_bill_id' => $openBill->id,
                        'fulfillment_type' => $fulfillmentType,
                        'table_id' => $fulfillmentType === FulfillmentType::DineIn ? $table->id : null,
                        'table_name' => $fulfillmentType === FulfillmentType::DineIn ? $table->name : null,
                        'subtotal' => 0,
                    ]);
                } elseif ($fulfillmentType === FulfillmentType::DineIn) {
                    $group->update([
                        'table_id' => $table->id,
                        'table_name' => $table->name,
                    ]);
                }

                foreach ($groupPayload['items'] as $itemPayload) {
                    $menuItem = MenuItem::findOrFail($itemPayload['menu_item_id']);
                    $quantity = max(1, (int) $itemPayload['quantity']);
                    $note = isset($itemPayload['note']) ? trim((string) $itemPayload['note']) : null;
                    $existing = $group->items()->where('menu_item_id', $menuItem->id)->first();

                    if ($existing) {
                        $update = ['quantity' => $existing->quantity + $quantity];
                        if ($note !== null && $note !== '') {
                            $update['note'] = $note;
                        }
                        $existing->update($update);
                    } else {
                        $group->items()->create([
                            'menu_item_id' => $menuItem->id,
                            'quantity' => $quantity,
                            'unit_price' => $menuItem->price,
                            'added_at' => now(),
                            'note' => $note !== '' ? $note : null,
                        ]);
                    }

                    $this->stockService->deductForMenuItem($menuItem, $quantity);
                }

                $group->recalculateSubtotal();
            }

            OpenBillInvolvedStaff::firstOrCreate(
                ['open_bill_id' => $openBill->id, 'staff_id' => $staff->id],
                ['staff_name' => $staff->name]
            );

            $table->update(['active_open_bill_id' => $openBill->id]);

            return $openBill->fresh()->load(['groups.items.menuItem', 'involvedStaff', 'member']);
        });

        $table->refresh()->load('orderItems.menuItem');

        return response()->json([
            'data' => $openBill,
            'active_open_bill_id' => $openBill->id,
            'table_bill' => $this->billingService->calculateTableBill($table),
        ]);
    }

    public function removeOrder(Request $request, Table $table, string $menuItemId)
    {
        $item = $table->orderItems()->with('menuItem')->where('menu_item_id', $menuItemId)->first();
        if ($item?->menuItem) {
            $this->stockService->restockForMenuItem($item->menuItem, $item->quantity);
        }
        $item?->delete();

        return response()->json(['data' => $table->fresh()->load('orderItems.menuItem')]);
    }

    public function updateOrder(Request $request, Table $table, string $menuItemId)
    {
        $data = $request->validate(['quantity' => 'required|integer|min:0']);
        $item = $table->orderItems()->with('menuItem')->where('menu_item_id', $menuItemId)->first();

        if (!$item) {
            return response()->json(['message' => 'Item order tidak ditemukan.'], 404);
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

            $item->update(['quantity' => $data['quantity']]);
        }

        return response()->json(['data' => $table->fresh()->load('orderItems.menuItem')]);
    }

    public function bill(Table $table)
    {
        $bill = $this->billingService->calculateTableBill($table);
        return response()->json(['data' => $bill]);
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
