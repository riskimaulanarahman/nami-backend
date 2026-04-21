<?php

namespace App\Services;

use App\Enums\MemberTier;
use App\Enums\PointLedgerType;
use App\Models\Member;
use App\Models\MemberPointLedger;

class MemberPointService
{
    public const POINTS_PER_RUPIAH = 10000; // 1 point per 10k
    public const POINT_VALUE_RUPIAH = 100;   // 1 point = Rp100
    public const MAX_REDEEM_PERCENT = 0.5;   // max 50% of subtotal

    /**
     * Calculate points earned for a given subtotal.
     */
    public function calculatePointsEarned(int $subtotal): int
    {
        return intval(floor($subtotal / self::POINTS_PER_RUPIAH));
    }

    /**
     * Calculate max redeemable points for a given subtotal and member balance.
     */
    public function calculateMaxRedeemPoints(int $subtotal, int $memberBalance): int
    {
        $maxRedeemAmount = intval(floor($subtotal * self::MAX_REDEEM_PERCENT));
        $maxPoints = intval(floor($maxRedeemAmount / self::POINT_VALUE_RUPIAH));
        return min($memberBalance, $maxPoints);
    }

    /**
     * Validate and clamp points to redeem.
     */
    public function clampPointsToRedeem(int $requestedPoints, int $subtotal, int $memberBalance): int
    {
        $maxAllowed = $this->calculateMaxRedeemPoints($subtotal, $memberBalance);
        return min($requestedPoints, $maxAllowed);
    }

    /**
     * Convert points to Rupiah.
     */
    public function pointsToRupiah(int $points): int
    {
        return $points * self::POINT_VALUE_RUPIAH;
    }

    /**
     * Process earn after checkout.
     */
    public function earn(Member $member, string $orderId, int $subtotal, string $billCode): int
    {
        $points = $this->calculatePointsEarned($subtotal);
        if ($points <= 0) return 0;

        MemberPointLedger::create([
            'member_id' => $member->id,
            'order_id' => $orderId,
            'type' => PointLedgerType::Earn,
            'points' => $points,
            'amount' => $subtotal,
            'note' => "Poin dari {$billCode}",
        ]);

        $member->increment('points_balance', $points);
        $member->refreshTier();

        return $points;
    }

    /**
     * Process redeem during checkout.
     */
    public function redeem(Member $member, string $orderId, int $points, string $billCode): int
    {
        if ($points <= 0) return 0;

        $redeemAmount = $this->pointsToRupiah($points);

        MemberPointLedger::create([
            'member_id' => $member->id,
            'order_id' => $orderId,
            'type' => PointLedgerType::Redeem,
            'points' => $points,
            'amount' => $redeemAmount,
            'note' => "Redeem pada {$billCode}",
        ]);

        $member->decrement('points_balance', $points);
        $member->refreshTier();

        return $redeemAmount;
    }
}
