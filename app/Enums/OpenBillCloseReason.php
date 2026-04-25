<?php

namespace App\Enums;

enum OpenBillCloseReason: string
{
    case ManualTableClose = 'manual-table-close';
    case PackageExpiredAuto = 'package-expired-auto';
}
