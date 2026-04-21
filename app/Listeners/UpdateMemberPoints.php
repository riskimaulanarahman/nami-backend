<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Events\OrderRefunded;
use App\Services\MemberPointService;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdateMemberPoints implements ShouldQueue
{
    public function __construct(private MemberPointService $pointService) {}

    public function handle(OrderCompleted|OrderRefunded $event): void
    {
        $order = $event->order;

        if ($event instanceof OrderCompleted) {
            // Processing for earn/redeem is currently handled in OrderService for transactional integrity.
            // This listener could be used for external point system syncing or extended logic.
        }

        if ($event instanceof OrderRefunded) {
            // Reverse points logic if applicable
        }
    }
}
