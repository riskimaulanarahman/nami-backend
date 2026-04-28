<?php

namespace App\Listeners;

use App\Events\ShiftClosed;
use App\Mail\ShiftStockSummary;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendShiftStockEmail
{
    public function handle(ShiftClosed $event): void
    {
        try {
            $shift = $event->shift;
            $shift->loadMissing('tenant');

            $ownerEmail = $shift->tenant?->email;
            if (!$ownerEmail) {
                Log::warning('SendShiftStockEmail: tenant email not found for shift ' . $shift->id);
                return;
            }

            Mail::to($ownerEmail)->send(new ShiftStockSummary($shift));
        } catch (\Throwable $e) {
            Log::error('SendShiftStockEmail failed: ' . $e->getMessage(), [
                'shift_id' => $event->shift->id,
            ]);
        }
    }
}
