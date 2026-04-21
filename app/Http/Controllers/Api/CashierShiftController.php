<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Services\CashierShiftService;
use Illuminate\Http\Request;

class CashierShiftController extends Controller
{
    public function __construct(private CashierShiftService $shiftService) {}

    public function index()
    {
        return response()->json([
            'data' => CashierShift::with('involvedStaff')->orderByDesc('opened_at')->paginate(50),
        ]);
    }

    public function active()
    {
        $shift = $this->shiftService->getActiveShift();
        return response()->json(['data' => $shift?->load('involvedStaff')]);
    }

    public function open(Request $request)
    {
        $data = $request->validate([
            'opening_cash' => 'required|integer|min:0',
            'note' => 'nullable|string',
        ]);

        try {
            $shift = $this->shiftService->openShift($request->user(), $data['opening_cash'], $data['note'] ?? null);
            return response()->json(['data' => $shift->load('involvedStaff')], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function close(Request $request)
    {
        $data = $request->validate([
            'closing_cash' => 'required|integer|min:0',
            'note' => 'nullable|string',
        ]);

        $shift = $this->shiftService->getActiveShift();
        if (!$shift) return response()->json(['message' => 'Tidak ada shift aktif.'], 422);

        try {
            $closed = $this->shiftService->closeShift($shift, $data['closing_cash'], $data['note'] ?? null);
            return response()->json(['data' => $closed->load('involvedStaff')]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(CashierShift $cashierShift)
    {
        return response()->json(['data' => $cashierShift->load('involvedStaff')]);
    }

    public function transactions(CashierShift $cashierShift)
    {
        return response()->json([
            'data' => $cashierShift->orders()->with(['groups.items', 'involvedStaff'])->orderByDesc('created_at')->get(),
        ]);
    }
}
