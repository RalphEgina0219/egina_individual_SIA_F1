<?php

namespace App\Services\Integration;

use App\Models\IntegrationOutbox;
use App\Models\Order;
use Throwable;

/**
 * F3: CRM → INV. Bawat benta ay SALE_OUT; bawat cancel ay SALE_RETURN.
 *
 * Inilalagay lang sa outbox (sa parehong transaction ng benta); ang
 * integration:process-outbox ang magpapadala. Hindi nito pinapalitan ang
 * lumang pagbawas ng stock ng CRM; dagdag lang ito.
 * Kapag may problema (hal. walang SKU), HINDI pinipigilan ang benta:
 * failed ang stock_sync_status at may log para makita ng Admin.
 */
class SalesStockSync
{
    public const TARGET = 'INV';
    public const ENDPOINT = '/stock-movements';

    public function queueSaleOut(Order $order): void
    {
        $this->queue($order, 'SALE_OUT', $order->transaction_number, 'Sale');
    }

    public function queueSaleReturn(Order $order): void
    {
        $saleOut = Outbox::find(self::TARGET, self::ENDPOINT, (string) $order->transaction_number);

        // Walang SALE_OUT na naipadala o ipapadala = walang ibabalik sa INV.
        if (!$saleOut || $saleOut->status === IntegrationOutbox::STATUS_FAILED) {
            return;
        }

        $this->queue($order, 'SALE_RETURN', $order->transaction_number . OutboxHandlers::RETURN_SUFFIX, "Cancelled sale {$order->transaction_number}");
    }

    private function queue(Order $order, string $type, string $reference, string $remarks): void
    {
        try {
            [$payload, $problem] = $this->payload($order, $type, $reference, $remarks);

            if ($problem) {
                // Sa integration_logs (database) isinusulat, hindi sa log file, para
                // hindi kailanman masira ang benta dahil sa problema ng log file.
                Outbox::logProblem(self::TARGET, self::ENDPOINT, $reference, $problem, $payload);
                $order->update(['stock_sync_status' => 'failed']);

                return;
            }

            Outbox::push(self::TARGET, 'POST', self::ENDPOINT, $payload, $reference);
            $order->update(['stock_sync_status' => 'pending']);
        } catch (Throwable $e) {
            // Ang benta ang mas mahalaga: huwag itong pigilan dahil sa integration.
            try {
                Outbox::logProblem(self::TARGET, self::ENDPOINT, $reference, 'Could not queue: ' . $e->getMessage());
                $order->update(['stock_sync_status' => 'failed']);
            } catch (Throwable) {
                // walang magagawa; tuloy pa rin ang benta
            }
        }
    }

    /**
     * @return array{0: array, 1: ?string} [payload, problema kung mayroon]
     */
    private function payload(Order $order, string $type, string $reference, string $remarks): array
    {
        $order->loadMissing(['orderItems.product', 'branch', 'user']);

        $items = [];
        $missing = [];
        foreach ($order->orderItems as $item) {
            $product = $item->product;
            if (!$product || !$product->hasInventorySku()) {
                $missing[] = ($product?->product_name ?? "product #{$item->product_id}") . ' (sku: ' . ($product?->sku ?? 'none') . ')';
                continue;
            }
            $items[$product->sku] = ($items[$product->sku] ?? 0) + (int) $item->quantity;
        }

        $branchCode = collect([$order->branch?->code, $order->user?->branch_code])
            ->first(fn ($code) => MasterCodes::isBranch($code));

        $payload = [
            'movement_type' => $type,
            'source_system' => config('integration.system_code', 'CRM'),
            'reference_number' => $reference,
            'branch_code' => $branchCode,
            'warehouse_code' => null,
            'performed_by' => $order->cashier_employee_no,
            'items' => collect($items)->map(fn ($qty, $sku) => ['sku' => $sku, 'quantity' => $qty])->values()->all(),
            'remarks' => $remarks,
        ];

        $problem = match (true) {
            $missing !== [] => 'No INV SKU (SKU-xxxx) for: ' . implode(', ', $missing),
            $branchCode === null => 'No branch_code (BR-xxx): set the order branch, or map the branch with integration:map-master-data',
            default => null,
        };

        return [$payload, $problem];
    }
}
