<?php

namespace App\Enums;

enum PaymentMethodType: string
{
    case Cash = 'cash';
    case NonCash = 'non-cash';
}
