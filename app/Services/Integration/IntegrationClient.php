<?php

namespace App\Services\Integration;

use App\Models\IntegrationLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Tumatawag sa ibang system (PROC, INV, HR) gamit ang X-API-KEY nila.
 * Lahat ng tawag ay isinusulat sa integration_logs (B7).
 */
class IntegrationClient
{
    public function isConfigured(string $system): bool
    {
        return (string) config("integration.systems.{$system}.url") !== '';
    }

    /**
     * @throws ConnectionException kapag hindi maabot ang system
     * @throws RuntimeException kapag walang URL sa .env
     */
    public function send(string $system, string $method, string $endpoint, array $payload = [], ?string $referenceNumber = null): Response
    {
        $base = rtrim((string) config("integration.systems.{$system}.url"), '/');
        $method = strtoupper($method);
        $url = $base . '/' . ltrim($endpoint, '/');
        $isGet = $method === 'GET';
        $started = microtime(true);

        if ($base === '') {
            $this->log($system, $method, $endpoint, $referenceNumber, $isGet ? null : $payload, null, null, $started, "{$system}_API_URL is not set in .env");
            throw new RuntimeException("{$system}_API_URL is not set in .env");
        }

        try {
            $request = Http::withHeaders([
                'X-API-KEY' => (string) config("integration.systems.{$system}.key"),
                'X-Source-System' => (string) config('integration.system_code'),
            ])->acceptJson()->timeout((int) config('integration.timeout', 10));

            $response = $isGet
                ? $request->get($url, $payload)
                : $request->send($method, $url, ['json' => $payload]);
        } catch (Throwable $e) {
            $this->log($system, $method, $url, $referenceNumber, $isGet ? null : $payload, null, null, $started, $e->getMessage());
            throw $e;
        }

        $this->log(
            $system, $method, $isGet && $payload ? $url . '?' . http_build_query($payload) : $url,
            $referenceNumber, $isGet ? null : $payload, $response->status(), $this->decode($response), $started,
            $response->successful() ? null : "HTTP {$response->status()}"
        );

        return $response;
    }

    public function get(string $system, string $endpoint, array $query = []): Response
    {
        return $this->send($system, 'GET', $endpoint, $query);
    }

    /**
     * GET {system}/health. Sumasagot ng ['ok' => bool, 'message' => string].
     */
    public function health(string $system): array
    {
        if (!$this->isConfigured($system)) {
            return ['ok' => false, 'configured' => false, 'message' => 'Not configured'];
        }

        try {
            $response = $this->get($system, '/health');
            $ok = $response->successful() && $response->json('success') === true;

            return [
                'ok' => $ok,
                'configured' => true,
                'message' => $ok ? 'Online' : "HTTP {$response->status()}",
                'time' => $response->json('time'),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'configured' => true, 'message' => 'Unreachable: ' . $e->getMessage()];
        }
    }

    private function decode(Response $response): ?array
    {
        $json = $response->json();
        if (is_array($json)) {
            // Malalaking listahan: bilang lang ng items ang itinatago sa log.
            if (isset($json['data']['items']) && is_array($json['data']['items'])) {
                $json['data']['items'] = ['count' => count($json['data']['items'])];
            }

            return $json;
        }

        $body = trim($response->body());

        return $body === '' ? null : ['raw' => mb_substr($body, 0, 2000)];
    }

    private function log(string $system, string $method, string $url, ?string $reference, ?array $request, ?int $status, ?array $response, float $started, ?string $error): void
    {
        try {
            IntegrationLog::create([
                'direction' => 'out',
                'system' => $system,
                'method' => $method,
                'url' => mb_substr($url, 0, 500),
                'reference_number' => $reference,
                'request_body' => $request ?: null,
                'status_code' => $status,
                'response_body' => $response,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $error,
            ]);
        } catch (Throwable $e) {
            rescue(fn () => report($e), report: false);
        }
    }
}
