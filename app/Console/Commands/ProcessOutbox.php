<?php

namespace App\Console\Commands;

use App\Models\IntegrationOutbox;
use App\Services\Integration\IntegrationClient;
use App\Services\Integration\OutboxHandlers;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan integration:process-outbox
 *
 * Ipinapadala ang mga pending na row ng integration_outbox (B7).
 * - INTEGRATION_ENABLED=false: walang ipapadala; mananatiling pending.
 * - 2xx: sent.
 * - 422: failed agad (mali ang data; hindi aayos kahit ulitin).
 * - Iba pang error / hindi maabot: attempts + 1, susubukan ulit
 *   pagkalipas ng 1, 2, 4, 8 minuto; failed pagdating ng 5 attempts.
 */
class ProcessOutbox extends Command
{
    protected $signature = 'integration:process-outbox {--limit=50 : Pinakamaraming rows bawat takbo}';

    protected $description = 'Send pending integration_outbox rows to the other systems';

    public function handle(IntegrationClient $client, OutboxHandlers $handlers): int
    {
        if (!config('integration.enabled')) {
            $pending = IntegrationOutbox::where('status', IntegrationOutbox::STATUS_PENDING)->count();
            $this->info("INTEGRATION_ENABLED=false: nothing sent ({$pending} pending row(s) kept).");

            return self::SUCCESS;
        }

        $rows = IntegrationOutbox::where('status', IntegrationOutbox::STATUS_PENDING)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $sent = $failed = $retry = $waiting = 0;

        foreach ($rows as $row) {
            [$decision, $reason] = $handlers->beforeSend($row) + [1 => null];

            if ($decision === 'wait') {
                $waiting++;
                continue;
            }
            if ($decision === 'fail') {
                $this->markFailed($row, $reason, $handlers);
                $failed++;
                continue;
            }

            try {
                $response = $client->send($row->target_system, $row->method, $row->endpoint, $row->payload ?? [], $row->reference_number);

                if ($response->successful()) {
                    $row->update([
                        'status' => IntegrationOutbox::STATUS_SENT,
                        'attempts' => $row->attempts + 1,
                        'sent_at' => now(),
                        'last_error' => null,
                        'next_attempt_at' => null,
                    ]);
                    $handlers->onSent($row, $response->json());
                    $sent++;
                    continue;
                }

                $error = "HTTP {$response->status()}: " . mb_substr($response->json('message') ?? $response->body(), 0, 500);
                if ($errors = $response->json('errors')) {
                    $error .= ' ' . json_encode($errors);
                }

                if ($response->status() === 422) {
                    $row->attempts++;
                    $this->markFailed($row, $error, $handlers);
                    $failed++;
                    continue;
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            $this->scheduleRetry($row, $error, $handlers) ? $retry++ : $failed++;
        }

        $this->info("Outbox: {$sent} sent, {$retry} will retry, {$failed} failed, {$waiting} waiting.");

        return self::SUCCESS;
    }

    /**
     * @return bool true kung susubukan ulit, false kung failed na
     */
    private function scheduleRetry(IntegrationOutbox $row, string $error, OutboxHandlers $handlers): bool
    {
        $attempts = $row->attempts + 1;
        $max = (int) config('integration.outbox.max_attempts', 5);

        if ($attempts >= $max) {
            $row->attempts = $attempts;
            $this->markFailed($row, $error, $handlers);

            return false;
        }

        $backoff = config('integration.outbox.backoff_minutes', [1, 2, 4, 8]);
        $minutes = $backoff[min($attempts - 1, count($backoff) - 1)];

        $row->update([
            'attempts' => $attempts,
            'last_error' => mb_substr($error, 0, 2000),
            'next_attempt_at' => now()->addMinutes($minutes),
        ]);

        return true;
    }

    private function markFailed(IntegrationOutbox $row, ?string $error, OutboxHandlers $handlers): void
    {
        $row->update([
            'status' => IntegrationOutbox::STATUS_FAILED,
            'attempts' => $row->attempts,
            'last_error' => mb_substr((string) $error, 0, 2000),
            'next_attempt_at' => null,
        ]);
        $handlers->onFailed($row);
    }
}
