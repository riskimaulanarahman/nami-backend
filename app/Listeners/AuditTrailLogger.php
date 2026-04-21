<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;

class AuditTrailLogger
{
    public function handle(mixed $event): void
    {
        $eventName = class_basename($event);
        Log::info("AUDIT: Event {$eventName} dipicu.", [
            'event_data' => $event,
            'timestamp' => now()->toDateTimeString(),
        ]);
    }
}
