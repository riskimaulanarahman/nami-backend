<?php

namespace App\Enums;

enum OpenBillStatus: string
{
    case Open = 'open';
    case Draft = 'draft';
    case Closed = 'closed';
}
