<?php

namespace App\Http\Middleware;

use App\Models\IntegrationLog;
use App\Support\IntegrationResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sinusuri ang X-API-KEY ng mga tawag galing sa ibang system (B5),
 * at isinusulat ang bawat papasok na tawag sa integration_logs (B7).
 */
class VerifyIntegrationKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $expected = (string) config('integration.api_key');
        $given = (string) $request->header('X-API-KEY', '');

        // Walang naka-set na key = sarado ang lahat (hindi bukas sa lahat).
        $response = ($expected === '' || $given === '' || !hash_equals($expected, $given))
            ? IntegrationResponse::error('Invalid or missing API key.', ['api_key' => ['The X-API-KEY header is missing or wrong.']], 401)
            : $next($request);

        $this->log($request, $response, $started);

        return $response;
    }

    private function log(Request $request, Response $response, float $started): void
    {
        try {
            $body = $request->isMethod('GET') ? ($request->query() ?: null) : ($request->json()->all() ?: null);
            $responseBody = json_decode((string) $response->getContent(), true);

            IntegrationLog::create([
                'direction' => 'in',
                'system' => $this->caller($request),
                'method' => $request->method(),
                'url' => substr($request->fullUrl(), 0, 500),
                'reference_number' => $request->input('reference_number'),
                'request_body' => $body,
                'status_code' => $response->getStatusCode(),
                // Malalaking listahan: buod lang ang itinatago.
                'response_body' => is_array($responseBody) ? $this->shrink($responseBody) : null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (Throwable $e) {
            // Hindi dapat masira ang sagot dahil lang hindi nakapag-log.
            rescue(fn () => report($e), report: false);
        }
    }

    private function caller(Request $request): string
    {
        $system = strtoupper((string) ($request->header('X-Source-System') ?: $request->input('source_system', '')));

        return in_array($system, ['CRM', 'PROC', 'INV', 'HR'], true) ? $system : 'UNKNOWN';
    }

    private function shrink(array $body): array
    {
        if (isset($body['data']['items']) && is_array($body['data']['items'])) {
            $body['data']['items'] = ['count' => count($body['data']['items'])];
        }

        return $body;
    }
}
