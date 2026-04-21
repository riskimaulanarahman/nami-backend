<?php

namespace App\Enums;

enum FulfillmentType: string
{
    case DineIn = 'dine-in';
    case Takeaway = 'takeaway';
}
