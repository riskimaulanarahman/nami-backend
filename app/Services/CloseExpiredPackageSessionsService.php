<?php

namespace App\Services;

use App\Enums\BillingMode;
use App\Enums\SessionType;
use App\Enums\TableStatus;
use App\Models\Table;

class CloseExpiredPackageSessionsService
{
    private const FALLBACK_LOCK_TTL_SECONDS = 15;

    public function __construct(
        private TableDraftService $tableDraftService,
    ) {}

    public function handle(?string $tenantId = null): array
    {
        $processed = 0;
        $skipped = 0;
        $failed = 0;

        $query = Table::query()
            ->withoutGlobalScopes()
            ->select('id')
            ->where('status', TableStatus::Occupied->value)
            ->where('session_type', SessionType::Billiard->value)
            ->where('billing_mode', BillingMode::Package->value)
            ->whereNotNull('package_expired_at')
            ->where('package_expired_at', '<=', now())
            ->orderBy('id');

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $query
            ->chunkById(100, function ($tables) use (&$processed, &$skipped, &$failed) {
                foreach ($tables as $candidate) {
                    try {
                        $draft = $this->tableDraftService->closeExpiredPackageToDraft($candidate);
                        if ($draft) {
                            $processed++;
                        } else {
                            $skipped++;
                        }
                    } catch (\Throwable $error) {
                        report($error);
                        $failed++;
                    }
                }
            });

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    public function runTenantFallback(string $tenantId): array
    {
        $lockKey = "fallback-close-expired-package-sessions:{$tenantId}";

        if (!cache()->add($lockKey, now()->toISOString(), self::FALLBACK_LOCK_TTL_SECONDS)) {
            return [
                'processed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'locked' => true,
            ];
        }

        try {
            return $this->handle($tenantId) + ['locked' => false];
        } finally {
            cache()->forget($lockKey);
        }
    }
}
