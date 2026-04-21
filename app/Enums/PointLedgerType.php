<?php

namespace App\Enums;

enum PointLedgerType: string
{
    case Earn = 'earn';
    case Redeem = 'redeem';
    case Adjustment = 'adjustment';
}
