<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Http\Resources\OrderResource;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService) {}

    public function index(Request $request)
    {
        $query = Order::with(['groups.items', 'involvedStaff'])->orderByDesc('created_at');

        if ($request->has('status')) $query->where('status', $request->status);
        if ($request->has('session_type')) $query->where('session_type', $request->session_type);
        if ($request->has('from')) $query->where('created_at', '>=', $request->from);
        if ($request->has('to')) $query->where('created_at', '<=', $request->to);
        if ($request->has('shift_id')) $query->where('cashier_shift_id', $request->shift_id);
        if ($request->has('search')) {
            $query->where(fn ($q) => $q
                ->where('table_name', 'like', "%{$request->search}%")
                ->orWhere('served_by', 'like', "%{$request->search}%")
                ->orWhere('member_name', 'like', "%{$request->search}%")
            );
        }

        return OrderResource::collection($query->paginate(50));
    }

    public function show(Order $order)
    {
        return new OrderResource($order->load(['groups.items', 'involvedStaff']));
    }

    public function refund(Request $request, Order $order)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $staff = $request->user();
        $shift = $request->input('active_shift');

        $order = $this->orderService->refundOrder($order, $staff, $shift, $data['reason']);

        return response()->json(['data' => $order->load(['groups.items', 'involvedStaff'])]);
    }
}
