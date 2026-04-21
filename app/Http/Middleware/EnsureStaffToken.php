<?php

namespace App\Http\Middleware;

use App\Models\Staff;
use Closure;
use Illuminate\Http\Request;

class EnsureStaffToken
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() instanceof Staff) {
            return response()->json([
                'message' => 'Token staff diperlukan.',
            ], 403);
        }

        return $next($request);
    }
}

