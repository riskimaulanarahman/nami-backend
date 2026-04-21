<?php

namespace App\Enums;

enum CashierShiftStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
    case Legacy = 'legacy';
}
