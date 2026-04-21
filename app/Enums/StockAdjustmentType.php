<?php

namespace App\Enums;

enum StockAdjustmentType: string
{
    case In = 'in';
    case Out = 'out';
    case Adjustment = 'adjustment';
}
