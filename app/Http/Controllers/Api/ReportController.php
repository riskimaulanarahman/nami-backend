<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\OpenBill;
use App\Models\Order;
use App\Models\Table;
use App\Models\WaitingListEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function dashboard()
    {
        $totalRevenue = Order::where('status', OrderStatus::Completed)->sum('grand_total');
        $activeSessions = Table::where('status', 'occupied')->count();
        $availableTables = Table::where('status', 'available')->count();
        $waitingCount = WaitingListEntry::where('status', 'waiting')->count();
        $openBillCount = OpenBill::where('status', 'open')->count();
        $lowStockCount = Ingredient::whereColumn('stock', '<=', 'min_stock')->count();

        return response()->json(['data' => [
            'total_revenue' => $totalRevenue,
            'active_sessions_count' => $activeSessions,
            'available_tables' => $availableTables,
            'waiting_count' => $waitingCount,
            'open_bill_count' => $openBillCount,
            'low_stock_count' => $lowStockCount,
        ]]);
    }

    public function billiard(Request $request)
    {
        $salesQuery = Order::query()
            ->where('session_type', 'billiard')
            ->whereIn('status', [OrderStatus::Completed, OrderStatus::Refunded]);
        $refundedQuery = Order::query()
            ->where('session_type', 'billiard')
            ->where('status', OrderStatus::Refunded);

        $this->applyDateRange($salesQuery, $request);
        $this->applyDateRange($refundedQuery, $request);

        $salesOrders = $salesQuery->get();
        $refundedOrders = $refundedQuery
            ->orderByDesc('refunded_at')
            ->get();
        $grossRevenue = (int) $salesOrders->sum('grand_total');
        $refundTotal = (int) $refundedOrders->sum('grand_total');

        return response()->json(['data' => [
            'total_sessions' => $salesOrders->count(),
            'total_revenue' => $grossRevenue,
            'gross_revenue' => $grossRevenue,
            'refund_total' => $refundTotal,
            'refund_count' => $refundedOrders->count(),
            'net_revenue' => $grossRevenue - $refundTotal,
            'total_rental' => $salesOrders->sum('rental_cost'),
            'total_fnb' => $salesOrders->sum('order_total'),
            'avg_duration' => $salesOrders->avg('duration_minutes'),
            'package_count' => $salesOrders->where('billiard_billing_mode', 'package')->count(),
            'open_bill_count' => $salesOrders->where('billiard_billing_mode', 'open-bill')->count(),
            'recent_refunds' => $this->mapRecentRefunds($refundedOrders),
        ]]);
    }

    public function fnb(Request $request)
    {
        $salesQuery = Order::query()
            ->where('session_type', 'cafe')
            ->whereIn('status', [OrderStatus::Completed, OrderStatus::Refunded]);
        $refundedQuery = Order::query()
            ->where('session_type', 'cafe')
            ->where('status', OrderStatus::Refunded);

        $this->applyDateRange($salesQuery, $request);
        $this->applyDateRange($refundedQuery, $request);

        $salesOrders = $salesQuery->get();
        $refundedOrders = $refundedQuery
            ->orderByDesc('refunded_at')
            ->get();
        $grossRevenue = (int) $salesOrders->sum('grand_total');
        $refundTotal = (int) $refundedOrders->sum('grand_total');
        $totalCost = (int) $salesOrders->sum('order_cost');

        return response()->json(['data' => [
            'total_orders' => $salesOrders->count(),
            'total_revenue' => $grossRevenue,
            'gross_revenue' => $grossRevenue,
            'refund_total' => $refundTotal,
            'refund_count' => $refundedOrders->count(),
            'net_revenue' => $grossRevenue - $refundTotal,
            'total_cost' => $totalCost,
            'total_profit' => $grossRevenue - $totalCost,
            'net_profit' => ($grossRevenue - $refundTotal) - $totalCost,
            'avg_order_value' => $salesOrders->count() > 0 ? round($salesOrders->avg('grand_total')) : 0,
            'recent_refunds' => $this->mapRecentRefunds($refundedOrders),
        ]]);
    }

    private function applyDateRange(Builder $query, Request $request): void
    {
        if ($request->has('from')) {
            $query->where('created_at', '>=', $request->from);
        }

        if ($request->has('to')) {
            $query->where('created_at', '<=', $request->to);
        }
    }

    private function mapRecentRefunds($orders)
    {
        return $orders
            ->take(8)
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'code' => $order->id,
                'table_name' => $order->table_name,
                'member_name' => $order->member_name,
                'session_type' => $order->session_type,
                'grand_total' => $order->grand_total,
                'refunded_at' => $order->refunded_at,
                'refunded_by' => $order->refunded_by,
                'refund_reason' => $order->refund_reason,
                'served_by' => $order->served_by,
                'status' => $order->status,
            ])
            ->values();
    }
}
