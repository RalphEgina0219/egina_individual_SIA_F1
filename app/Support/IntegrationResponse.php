<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Iisang format ng sagot para sa /api/integration/v1 (B5).
 *
 *   ok:    {"success": true,  "message": "...", "data": ...}
 *   error: {"success": false, "message": "...", "errors": {...}}
 */
class IntegrationResponse
{
    public static function ok(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function error(string $message, array $errors = [], int $status = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => (object) $errors,
        ], $status);
    }

    /**
     * Listahan na may "items" at "meta" (B5), mula sa isang collection.
     */
    public static function list(Collection $items, int $page = 1, int $perPage = 100, string $message = 'OK'): JsonResponse
    {
        $perPage = max(1, min($perPage, 500));
        $total = $items->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, $page);

        return self::ok([
            'items' => $items->slice(($page - 1) * $perPage, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'total' => $total,
            ],
        ], $message);
    }

    /**
     * Petsa sa ISO 8601 na may +08:00 (hal. 2026-11-23T10:15:00+08:00).
     */
    public static function date(?CarbonInterface $date): ?string
    {
        return $date?->copy()->setTimezone(config('integration.timezone', 'Asia/Manila'))->toIso8601String();
    }

    /**
     * Pera na laging 2 decimals, bilang string (hal. "320.00").
     */
    public static function money(int|float|string|null $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
