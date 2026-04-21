<?php

namespace App\Listeners;

use App\Events\ShiftClosed;
use App\Mail\ShiftClosedSummary;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendShiftClosedEmail
{
    public function handle(ShiftClosed $event): void
    {
        try {
            $shift = $event->shift;
            $shift->loadMissing('tenant');

            $ownerEmail = $shift->tenant?->email;
            if (!$ownerEmail) {
                Log::warning('SendShiftClosedEmail: tenant email not found for shift ' . $shift->id);
                return;
            }

            Mail::to($ownerEmail)->send(new ShiftClosedSummary($shift));
        } catch (\Throwable $e) {
            Log::error('SendShiftClosedEmail failed: ' . $e->getMessage(), [
                'shift_id' => $event->shift->id,
            ]);
        }
    }
}
