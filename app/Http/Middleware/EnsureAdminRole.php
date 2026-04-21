<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAdminRole
{
    public function handle(Request $request, Closure $next)
    {
        $staff = $request->user();
        if (!$staff || !$staff->isAdmin()) {
            return response()->json([
                'message' => 'Akses ditolak. Hanya admin yang bisa mengakses fitur ini.',
            ], 403);
        }
        return $next($request);
    }
}
