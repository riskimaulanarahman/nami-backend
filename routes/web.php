<?php

use App\Http\Controllers\Internal\InternalJobController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['internal.cron', 'throttle:6,1'])
    ->prefix('internal/jobs')
    ->group(function () {
        Route::get(
            '/close-expired-package-sessions',
            [InternalJobController::class, 'closeExpiredPackageSessions'],
        );
    });
