<?php

namespace App\Http\Middleware;

use App\Services\CashierShiftService;
use Closure;
use Illuminate\Http\Request;

class EnsureActiveShift
{
    public function __construct(private CashierShiftService $shiftService) {}

    public function handle(Request $request, Closure $next)
    {
        $shift = $this->shiftService->getActiveShift();
        if (!$shift) {
            return response()->json([
                'message' => 'Tidak ada shift kasir aktif. Buka shift terlebih dahulu.',
            ], 403);
        }

        // Verify the logged-in staff owns or is allowed on this shift
        $staff = $request->user();
        if ($staff && $shift->staff_id !== $staff->id && !$staff->isAdmin()) {
            return response()->json([
                'message' => 'Shift aktif bukan milik Anda. Silakan hubungi kasir shift.',
            ], 403);
        }

        // Attach shift to request for downstream use
        $request->merge(['active_shift' => $shift]);

        return $next($request);
    }
}
