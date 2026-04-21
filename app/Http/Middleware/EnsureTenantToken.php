<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

class EnsureTenantToken
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() instanceof Tenant) {
            return response()->json([
                'message' => 'Token tenant diperlukan.',
            ], 403);
        }

        return $next($request);
    }
}

