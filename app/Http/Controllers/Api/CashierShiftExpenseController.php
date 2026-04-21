<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Models\CashierShiftExpense;
use App\Services\CashierShiftService;
use Illuminate\Http\Request;

class CashierShiftExpenseController extends Controller
{
    public function __construct(private CashierShiftService $shiftService) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'amount'      => 'required|integer|min:1',
            'description' => 'required|string|max:255',
            'category'    => 'required|string|in:operational,supplies,utilities,transport,food_staff,other',
        ]);

        $shift = $this->shiftService->getActiveShift();
        if (!$shift) {
            return response()->json(['message' => 'Tidak ada shift aktif.'], 422);
        }

        $expense = CashierShiftExpense::create([
            'cashier_shift_id' => $shift->id,
            'staff_id'         => $request->user()->id,
            'staff_name'       => $request->user()->name,
            'amount'           => $data['amount'],
            'description'      => $data['description'],
            'category'         => $data['category'],
        ]);

        $shift->increment('total_expenses', $data['amount']);

        return response()->json(['data' => $expense], 201);
    }

    public function index(CashierShift $cashierShift)
    {
        $expenses = $cashierShift->expenses()->orderByDesc('created_at')->get();
        return response()->json(['data' => $expenses]);
    }

    public function destroy(Request $request, CashierShiftExpense $cashierShiftExpense)
    {
        $data = $request->validate([
            'delete_reason' => 'required|string|max:255',
        ]);

        $shift = $this->shiftService->getActiveShift();
        if (!$shift || $cashierShiftExpense->cashier_shift_id !== $shift->id) {
            return response()->json(['message' => 'Pengeluaran hanya bisa dihapus pada shift aktif.'], 422);
        }

        $cashierShiftExpense->update(['delete_reason' => $data['delete_reason']]);
        $cashierShiftExpense->delete();

        $shift->decrement('total_expenses', $cashierShiftExpense->amount);

        return response()->json(['message' => 'Pengeluaran berhasil dihapus.']);
    }
}
