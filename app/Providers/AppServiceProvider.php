<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Support\TenantContext;
use App\Events\OrderCompleted;
use App\Events\OrderRefunded;
use App\Events\ShiftOpened;
use App\Events\ShiftClosed;
use App\Events\StockLow;
use App\Listeners\UpdateMemberPoints;
use App\Listeners\NotifyLowStock;
use App\Listeners\AuditTrailLogger;
use App\Listeners\SendShiftClosedEmail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, fn () => new TenantContext());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(OrderCompleted::class, [UpdateMemberPoints::class, 'handle']);
        Event::listen(OrderCompleted::class, [AuditTrailLogger::class, 'handle']);
        
        Event::listen(OrderRefunded::class, [UpdateMemberPoints::class, 'handle']);
        Event::listen(OrderRefunded::class, [AuditTrailLogger::class, 'handle']);
        
        Event::listen(ShiftOpened::class, [AuditTrailLogger::class, 'handle']);
        Event::listen(ShiftClosed::class, [AuditTrailLogger::class, 'handle']);
        Event::listen(ShiftClosed::class, [SendShiftClosedEmail::class, 'handle']);
        
        Event::listen(StockLow::class, [NotifyLowStock::class, 'handle']);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
