<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Support\IntegrationResponse;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * GET /api/integration/v1/health (walang API key).
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'system' => config('integration.system_code'),
            'version' => config('integration.version'),
            'time' => IntegrationResponse::date(now()),
        ]);
    }
}
