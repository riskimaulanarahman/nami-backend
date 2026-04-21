<?php

namespace App\Enums;

enum BillingMode: string
{
    case OpenBill = 'open-bill';
    case Package = 'package';
}
