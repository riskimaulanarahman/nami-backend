<?php

namespace App\Enums;

enum OpenBillStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
