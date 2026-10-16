<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Liveness and readiness probes (API_SPEC.md §13).
 *
 * Responses never include dependency details; failures are logged server-side only.
 */
class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return $this->respond('ok', 200);
    }

    public function ready(): JsonResponse
    {
        try {
            DB::connection()->select('select 1');
        } catch (Throwable $e) {
            Log::warning('Readiness check failed: database unavailable.', ['exception' => $e::class]);

            return $this->respond('unavailable', 503);
        }

        if (! is_writable(storage_path('framework')) || ! is_writable(storage_path('app'))) {
            Log::warning('Readiness check failed: storage is not writable.');

            return $this->respond('unavailable', 503);
        }

        return $this->respond('ready', 200);
    }

    private function respond(string $status, int $code): JsonResponse
    {
        return response()
            ->json(['status' => $status], $code)
            ->header('Cache-Control', 'no-store');
    }
}
