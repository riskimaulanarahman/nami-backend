<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalCronToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = (string) config('internal_jobs.token', '');
        $providedToken = (string) $request->query('token', '');

        if ($configuredToken === '' || $providedToken === '') {
            abort(403, 'Internal job token is required.');
        }

        if (!hash_equals($configuredToken, $providedToken)) {
            abort(403, 'Invalid internal job token.');
        }

        if (
            !app()->environment(['local', 'testing']) &&
            !$request->isSecure() &&
            strtolower((string) $request->headers->get('x-forwarded-proto')) !== 'https'
        ) {
            abort(403, 'HTTPS is required for internal jobs.');
        }

        return $next($request);
    }
}
