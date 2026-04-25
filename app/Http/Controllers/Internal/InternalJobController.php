<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\CloseExpiredPackageSessionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

class InternalJobController extends Controller
{
    public function closeExpiredPackageSessions(
        CloseExpiredPackageSessionsService $service,
    ): JsonResponse {
        $startedAt = now();
        $lockKey = 'internal-job:close-expired-package-sessions';

        if (!Cache::add($lockKey, (string) $startedAt->toISOString(), now()->addSeconds(55))) {
            return response()->json([
                'status' => 'locked',
                'processed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'started_at' => $startedAt->toISOString(),
                'finished_at' => now()->toISOString(),
            ]);
        }

        try {
            $result = $service->handle();

            return response()->json([
                'status' => 'ok',
                'processed' => $result['processed'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
                'started_at' => $startedAt->toISOString(),
                'finished_at' => now()->toISOString(),
            ]);
        } catch (Throwable $error) {
            report($error);

            return response()->json([
                'status' => 'error',
                'processed' => 0,
                'skipped' => 0,
                'failed' => 1,
                'started_at' => $startedAt->toISOString(),
                'finished_at' => now()->toISOString(),
                'message' => $error->getMessage(),
            ], 500);
        } finally {
            Cache::forget($lockKey);
        }
    }
}
