<?php

namespace App\Enums;

enum MemberTier: string
{
    case Bronze = 'Bronze';
    case Silver = 'Silver';
    case Gold = 'Gold';

    public static function fromPoints(int $points): self
    {
        if ($points >= 500) return self::Gold;
        if ($points >= 200) return self::Silver;
        return self::Bronze;
    }
}
