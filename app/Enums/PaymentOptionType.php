<?php

namespace App\Enums;

enum PaymentOptionType: string
{
    case Cash = 'cash';
    case Qris = 'qris';
    case Transfer = 'transfer';
}
