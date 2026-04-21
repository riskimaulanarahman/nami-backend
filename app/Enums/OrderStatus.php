<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Completed = 'completed';
    case Refunded = 'refunded';
}
