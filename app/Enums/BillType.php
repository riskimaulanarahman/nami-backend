<?php

namespace App\Enums;

enum BillType: string
{
    case Billiard = 'billiard';
    case OpenBill = 'open-bill';
    case Package = 'package';
    case DineIn = 'dine-in';
    case Takeaway = 'takeaway';
    case Mixed = 'mixed';
}
