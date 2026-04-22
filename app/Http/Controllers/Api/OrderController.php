<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Staff;
use App\Models\Tenant;
use App\Services\OrderService;
use App\Http\Resources\OrderResource;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => 'nullable|string',
            'session_type' => 'nullable|string',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'shift_id' => 'nullable|string',
            'search' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $query = Order::with(['groups.items', 'involvedStaff'])->orderByDesc('created_at');

        if (!empty($validated['status'])) $query->where('status', $validated['status']);
        if (!empty($validated['session_type'])) $query->where('session_type', $validated['session_type']);
        if (!empty($validated['from'])) $query->where('created_at', '>=', Carbon::parse($validated['from'])->startOfDay());
        if (!empty($validated['to'])) $query->where('created_at', '<=', Carbon::parse($validated['to'])->endOfDay());
        if (!empty($validated['shift_id'])) $query->where('cashier_shift_id', $validated['shift_id']);
        if (!empty($validated['search'])) {
            $query->where(fn ($q) => $q
                ->where('id', 'like', "%{$validated['search']}%")
                ->orWhere('table_name', 'like', "%{$validated['search']}%")
                ->orWhere('served_by', 'like', "%{$validated['search']}%")
                ->orWhere('member_name', 'like', "%{$validated['search']}%")
                ->orWhere('payment_method_name', 'like', "%{$validated['search']}%")
                ->orWhere('payment_reference', 'like', "%{$validated['search']}%")
            );
        }

        return OrderResource::collection($query->paginate($validated['per_page'] ?? 50));
    }

    public function show(Order $order)
    {
        return new OrderResource($order->load(['groups.items', 'involvedStaff']));
    }

    public function refund(Request $request, Order $order)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:500',
            'admin_pin' => 'nullable|string|min:4|max:6',
            'owner_email' => 'nullable|email',
            'owner_password' => 'nullable|string|min:6',
        ]);

        /** @var Staff $staff */
        $staff = $request->user();
        $shift = $request->input('active_shift');
        $authorization = $this->resolveRefundAuthorization($request, $staff, $data);

        $order = $this->orderService->refundOrder(
            $order,
            $staff,
            $shift,
            $data['reason'],
            $authorization,
        );

        return response()->json(['data' => $order->load(['groups.items', 'involvedStaff'])]);
    }

    private function resolveRefundAuthorization(Request $request, Staff $staff, array $data): array
    {
        if ($staff->isAdmin()) {
            return [
                'method' => 'admin-session',
                'authorized_by' => $staff->name,
                'authorized_role' => 'admin',
                'owner_email' => null,
            ];
        }

        $tenantId = $staff->tenant_id;
        $adminPin = trim((string) ($data['admin_pin'] ?? ''));
        if ($adminPin !== '') {
            $admin = Staff::query()
                ->where('tenant_id', $tenantId)
                ->where('role', 'admin')
                ->where('is_active', true)
                ->get()
                ->first(fn (Staff $candidate) => Hash::check($adminPin, $candidate->pin));

            if (!$admin) {
                throw ValidationException::withMessages([
                    'admin_pin' => 'PIN admin tidak valid.',
                ]);
            }

            return [
                'method' => 'admin-pin',
                'authorized_by' => $admin->name,
                'authorized_role' => 'admin',
                'owner_email' => null,
            ];
        }

        $ownerEmail = strtolower(trim((string) ($data['owner_email'] ?? '')));
        $ownerPassword = (string) ($data['owner_password'] ?? '');
        if ($ownerEmail !== '' || $ownerPassword !== '') {
            if ($ownerEmail === '' || $ownerPassword === '') {
                throw ValidationException::withMessages([
                    'owner_email' => 'Email owner dan password owner wajib diisi bersamaan.',
                ]);
            }

            /** @var Tenant|null $tenant */
            $tenant = Tenant::query()
                ->whereKey($tenantId)
                ->where('email', $ownerEmail)
                ->first();

            if (!$tenant || !Hash::check($ownerPassword, $tenant->password)) {
                throw ValidationException::withMessages([
                    'owner_email' => 'Kredensial owner tidak valid.',
                ]);
            }

            return [
                'method' => 'owner-credentials',
                'authorized_by' => $tenant->name,
                'authorized_role' => 'owner',
                'owner_email' => $tenant->email,
            ];
        }

        throw ValidationException::withMessages([
            'authorization' => 'Refund membutuhkan PIN admin atau kredensial owner.',
        ]);
    }
}
