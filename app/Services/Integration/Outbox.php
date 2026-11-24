<?php

namespace App\Services\Integration;

use App\Models\IntegrationLog;
use App\Models\IntegrationOutbox;

/**
 * Naglalagay ng request sa integration_outbox. HINDI pa ito nagpapadala;
 * ang integration:process-outbox ang magpapadala mamaya (B7).
 *
 * Tawagin ito sa loob ng parehong database transaction ng benta / reklamo,
 * para sabay silang nase-save o sabay na hindi.
 */
class Outbox
{
    /**
     * Idempotent: kapag mayroon nang row para sa (target, endpoint, reference),
     * iyon ang ibinabalik at walang bagong row na ginagawa.
     */
    public static function push(string $targetSystem, string $method, string $endpoint, array $payload, string $referenceNumber): IntegrationOutbox
    {
        return IntegrationOutbox::firstOrCreate(
            [
                'target_system' => strtoupper($targetSystem),
                'endpoint' => $endpoint,
                'reference_number' => $referenceNumber,
            ],
            [
                'method' => strtoupper($method),
                'payload' => $payload,
                'status' => IntegrationOutbox::STATUS_PENDING,
                'attempts' => 0,
            ]
        );
    }

    public static function find(string $targetSystem, string $endpoint, string $referenceNumber): ?IntegrationOutbox
    {
        return IntegrationOutbox::where('target_system', strtoupper($targetSystem))
            ->where('endpoint', $endpoint)
            ->where('reference_number', $referenceNumber)
            ->first();
    }

    /**
     * "Retry" button: ibalik sa pending ang failed na row.
     */
    public static function retry(IntegrationOutbox $row, ?array $payload = null): IntegrationOutbox
    {
        $data = [
            'status' => IntegrationOutbox::STATUS_PENDING,
            'attempts' => 0,
            'last_error' => null,
            'next_attempt_at' => null,
        ];
        if ($payload !== null) {
            $data['payload'] = $payload;
        }
        $row->update($data);

        app(OutboxHandlers::class)->onRequeued($row);

        return $row;
    }

    /**
     * Para sa mga problemang nakita BAGO pa mailagay sa outbox
     * (hal. walang SKU ang produkto), para makita ng Admin sa logs.
     */
    public static function logProblem(string $targetSystem, string $endpoint, ?string $referenceNumber, string $error, ?array $payload = null): void
    {
        IntegrationLog::create([
            'direction' => 'out',
            'system' => strtoupper($targetSystem),
            'method' => 'POST',
            'url' => $endpoint,
            'reference_number' => $referenceNumber,
            'request_body' => $payload,
            'error' => $error,
        ]);
    }
}
