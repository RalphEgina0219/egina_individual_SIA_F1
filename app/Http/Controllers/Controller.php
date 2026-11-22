<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /**
     * Standard API success envelope: { success, message?, data }.
     */
    protected function ok(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        $body = ['success' => true];
        if ($message !== null) {
            $body['message'] = $message;
        }
        $body['data'] = $data;

        return response()->json($body, $status);
    }

    protected function perPage(\Illuminate\Http\Request $request, int $default = 10): int
    {
        return max(1, min((int) $request->get('per_page', $default), 100));
    }
}
