<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureStaffToken;
use App\Http\Middleware\EnsureTenantToken;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\VerifyInternalCronToken;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.context' => ResolveTenantContext::class,
            'tenant.token' => EnsureTenantToken::class,
            'staff.token' => EnsureStaffToken::class,
            'internal.cron' => VerifyInternalCronToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
