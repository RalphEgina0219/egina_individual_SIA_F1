<?php

namespace App\Services\Integration;

use App\Models\CustomerFeedback;
use App\Models\IntegrationOutbox;
use App\Models\Order;

/**
 * Ano ang gagawin ng CRM kapag naipadala, nag-fail, o ibinalik sa pending
 * ang isang outbox row. Dito naa-update ang orders.stock_sync_status at
 * ang customer_feedback.forward_status.
 */
class OutboxHandlers
{
    public const RETURN_SUFFIX = '-RET';

    /**
     * Bago magpadala. Ang SALE_RETURN ay ipinapadala lang pagkatapos
     * maipadala ang SALE_OUT, at hindi na ipinapadala kung nag-fail ang SALE_OUT
     * (para walang maibalik na stock na hindi naman nabawas sa INV).
     *
     * @return array{0: string, 1?: string} ['send'] | ['wait'] | ['fail', 'dahilan']
     */
    public function beforeSend(IntegrationOutbox $row): array
    {
        if ($row->endpoint !== '/stock-movements' || !str_ends_with($row->reference_number, self::RETURN_SUFFIX)) {
            return ['send'];
        }

        $saleOut = Outbox::find($row->target_system, $row->endpoint, substr($row->reference_number, 0, -strlen(self::RETURN_SUFFIX)));

        return match ($saleOut?->status) {
            IntegrationOutbox::STATUS_PENDING => ['wait'],
            IntegrationOutbox::STATUS_FAILED => ['fail', 'SALE_OUT was not posted to INV, so there is no stock to return.'],
            default => ['send'],
        };
    }

    public function onSent(IntegrationOutbox $row, ?array $response): void
    {
        if ($row->endpoint === '/stock-movements') {
            $this->refreshOrder($row);
        }

        if ($row->endpoint === '/supplier-issues') {
            $feedback = CustomerFeedback::where('feedback_number', $row->reference_number)->first();
            $feedback?->update([
                'forward_status' => 'sent',
                'supplier_issue_number' => data_get($response, 'data.issue_number', $feedback->supplier_issue_number),
                'supplier_code' => data_get($response, 'data.supplier_code') ?? $feedback->supplier_code,
            ]);
        }
    }

    public function onFailed(IntegrationOutbox $row): void
    {
        if ($row->endpoint === '/stock-movements') {
            $this->refreshOrder($row);
        }

        if ($row->endpoint === '/supplier-issues') {
            CustomerFeedback::where('feedback_number', $row->reference_number)->update(['forward_status' => 'failed']);
        }
    }

    public function onRequeued(IntegrationOutbox $row): void
    {
        if ($row->endpoint === '/stock-movements') {
            $this->refreshOrder($row);
        }

        if ($row->endpoint === '/supplier-issues') {
            CustomerFeedback::where('feedback_number', $row->reference_number)->update(['forward_status' => 'pending']);
        }
    }

    /**
     * Ang stock_sync_status ng benta ay galing sa SALE_OUT at (kung mayroon) SALE_RETURN:
     * may failed → failed; may pending → pending; lahat sent → synced.
     */
    public function refreshOrder(IntegrationOutbox $row): void
    {
        $txn = str_ends_with($row->reference_number, self::RETURN_SUFFIX)
            ? substr($row->reference_number, 0, -strlen(self::RETURN_SUFFIX))
            : $row->reference_number;

        $statuses = IntegrationOutbox::where('target_system', $row->target_system)
            ->where('endpoint', '/stock-movements')
            ->whereIn('reference_number', [$txn, $txn . self::RETURN_SUFFIX])
            ->pluck('status');

        $status = match (true) {
            $statuses->contains(IntegrationOutbox::STATUS_FAILED) => 'failed',
            $statuses->contains(IntegrationOutbox::STATUS_PENDING) => 'pending',
            $statuses->isNotEmpty() => 'synced',
            default => null,
        };

        if ($status) {
            Order::where('transaction_number', $txn)->update(['stock_sync_status' => $status]);
        }
    }
}
