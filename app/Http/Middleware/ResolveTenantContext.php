<?php

namespace App\Http\Middleware;

use App\Models\Staff;
use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenantContext->clear();

        $user = $request->user();
        $bearerToken = $request->bearerToken();

        if ($bearerToken) {
            $accessToken = PersonalAccessToken::findToken($bearerToken);
            $tokenable = $accessToken?->tokenable;

            if (
                $accessToken &&
                (!$accessToken->expires_at || $accessToken->expires_at->isFuture()) &&
                ($tokenable instanceof Tenant || $tokenable instanceof Staff)
            ) {
                $user = $tokenable->withAccessToken($accessToken);
                $request->setUserResolver(fn () => $user);
            } else {
                $user = null;
                $request->setUserResolver(fn () => null);
            }
        }

        if ($user instanceof Tenant) {
            $this->tenantContext->setTenantId($user->id);
        } elseif ($user instanceof Staff) {
            $this->tenantContext->setTenantId($user->tenant_id);
        }

        return $next($request);
    }
}
