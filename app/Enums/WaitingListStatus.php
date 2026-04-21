<?php

namespace App\Enums;

enum WaitingListStatus: string
{
    case Waiting = 'waiting';
    case Seated = 'seated';
    case Cancelled = 'cancelled';
}
