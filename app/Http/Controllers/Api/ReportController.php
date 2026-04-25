<?php

namespace App\Http\Controllers\Api;

use App\Enums\OpenBillStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\OpenBill;
use App\Models\Order;
use App\Models\Table;
use App\Models\WaitingListEntry;
use App\Services\PaymentOptionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ReportController extends Controller
{
    public function __construct(private PaymentOptionService $paymentOptionService) {}

    public function dashboard()
    {
        $totalRevenue = Order::where('status', OrderStatus::Completed)->sum('grand_total');
        $activeSessions = Table::where('status', 'occupied')->count();
        $availableTables = Table::where('status', 'available')->count();
        $waitingCount = WaitingListEntry::where('status', 'waiting')->count();
        $openBillCount = OpenBill::where('status', 'open')->count();
        $lowStockCount = Ingredient::query()
            ->when(
                Schema::hasColumn('ingredients', 'is_active'),
                fn (Builder $query) => $query->where('is_active', true)
            )
            ->whereColumn('stock', '<=', 'min_stock')
            ->count();
        $todayStart = Carbon::today();
        $todayEnd = Carbon::now();

        $todayOrders = Order::query()
            ->whereIn('status', [OrderStatus::Completed, OrderStatus::Refunded])
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->orderByDesc('created_at')
            ->get();

        $todayRevenue = (int) $todayOrders
            ->where('status', OrderStatus::Completed)
            ->sum('grand_total');
        $todayOrdersCount = $todayOrders->count();
        $avgTransaction = $todayOrdersCount > 0
            ? (int) round($todayRevenue / $todayOrdersCount)
            : 0;

        $hourlyRevenue = collect(range(0, 23))
            ->map(function (int $hour) use ($todayOrders) {
                $revenue = (int) $todayOrders
                    ->filter(function (Order $order) use ($hour) {
                        return (int) optional($order->created_at)->format('G') === $hour
                            && $order->status === OrderStatus::Completed;
                    })
                    ->sum('grand_total');

                return [
                    'hour' => str_pad((string) $hour, 2, '0', STR_PAD_LEFT),
                    'revenue' => $revenue,
                ];
            })
            ->values();

        $recentTransactions = $todayOrders
            ->take(6)
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'code' => $order->id,
                'table_name' => $order->table_name,
                'served_by' => $order->served_by,
                'session_type' => $order->session_type?->value ?? $order->session_type,
                'status' => $order->status?->value ?? $order->status,
                'grand_total' => $order->grand_total,
                'created_at' => $order->created_at,
            ])
            ->values();

        return response()->json(['data' => [
            'total_revenue' => $totalRevenue,
            'today_revenue' => $todayRevenue,
            'today_orders' => $todayOrdersCount,
            'avg_transaction' => $avgTransaction,
            'occupied_tables' => $activeSessions,
            'active_sessions_count' => $activeSessions,
            'available_tables' => $availableTables,
            'waiting_count' => $waitingCount,
            'open_bill_count' => $openBillCount,
            'low_stock_count' => $lowStockCount,
            'recent_transactions' => $recentTransactions,
            'hourly_revenue' => $hourlyRevenue,
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
        $this->applyDateRange($refundedQuery, $request, 'refunded_at');

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
        $this->applyDateRange($refundedQuery, $request, 'refunded_at');

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

    public function paymentMethods(Request $request)
    {
        $salesQuery = Order::query()
            ->whereIn('status', [OrderStatus::Completed, OrderStatus::Refunded]);

        $this->applyDateRange($salesQuery, $request);

        $orders = $salesQuery
            ->orderByDesc('created_at')
            ->get();

        $breakdown = $this->paymentOptionService
            ->summarizeTransactionsByPaymentMethod($orders, $request->user()->tenant_id);

        return response()->json(['data' => [
            'parents' => $breakdown,
            'total_transactions' => $orders->count(),
            'gross_revenue' => (int) $orders->sum('grand_total'),
            'net_revenue' => (int) $orders
                ->sum(fn (Order $order) => ($order->status === OrderStatus::Completed ? 1 : -1) * (int) $order->grand_total),
        ]]);
    }

    public function deletedDrafts(Request $request)
    {
        $query = OpenBill::onlyTrashed()
            ->whereIn('status', [
                OpenBillStatus::Open,
                OpenBillStatus::Draft,
            ])
            ->with(['groups.items'])
            ->orderByDesc('deleted_at');

        $this->applyDateRange($query, $request, 'deleted_at');

        $drafts = $query->get();

        $billiardDrafts = $drafts->filter(
            fn (OpenBill $draft) => $draft->reportSessionType() === 'billiard'
        );
        $fnbDrafts = $drafts->filter(
            fn (OpenBill $draft) => $draft->reportSessionType() !== 'billiard'
        );

        return response()->json(['data' => [
            'total_deleted_drafts' => $drafts->count(),
            'total_deleted_amount' => $drafts->sum(fn (OpenBill $draft) => $draft->draftTotalAmount()),
            'billiard_count' => $billiardDrafts->count(),
            'fnb_count' => $fnbDrafts->count(),
            'billiard_amount' => $billiardDrafts->sum(fn (OpenBill $draft) => $draft->draftTotalAmount()),
            'fnb_amount' => $fnbDrafts->sum(fn (OpenBill $draft) => $draft->draftTotalAmount()),
            'entries' => $this->mapDeletedDrafts($drafts),
        ]]);
    }

    private function applyDateRange(Builder $query, Request $request, string $column = 'created_at'): void
    {
        if ($request->has('from')) {
            $query->where($column, '>=', Carbon::parse($request->from)->startOfDay());
        }

        if ($request->has('to')) {
            $query->where($column, '<=', Carbon::parse($request->to)->endOfDay());
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

    private function mapDeletedDrafts($drafts)
    {
        return $drafts
            ->map(fn (OpenBill $draft) => [
                'id' => $draft->id,
                'code' => $draft->code,
                'customer_name' => $draft->customer_name,
                'table_name' => $draft->reportTableName(),
                'session_type' => $draft->reportSessionType(),
                'billing_mode' => $draft->billing_mode?->value,
                'total_amount' => $draft->draftTotalAmount(),
                'delete_reason' => $draft->delete_reason,
                'deleted_at' => $draft->deleted_at,
                'deleted_by_staff_name' => $draft->deleted_by_staff_name,
            ])
            ->values();
    }
}
