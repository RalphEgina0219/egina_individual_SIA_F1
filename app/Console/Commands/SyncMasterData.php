<?php

namespace App\Console\Commands;

use App\Models\IntegrationSyncCursor;
use App\Models\Product;
use App\Services\Integration\IntegrationClient;
use App\Services\Integration\MasterDataImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * php artisan integration:sync-masterdata   (Yugto 2)
 *
 * Kinukuha ang mga kopya galing sa may-ari, gamit ang ?updated_since=:
 * - HR:  /employees, /branches
 * - INV: /products, /warehouses, at /stock (pang-display na "INV stock")
 */
class SyncMasterData extends Command
{
    protected $signature = 'integration:sync-masterdata
        {--only= : HR o INV lang}
        {--full : Kunin lahat (huwag gamitin ang updated_since)}';

    protected $description = 'Copy employees, branches, products and warehouses from HR and INV';

    private const MAX_PAGES = 200;

    public function handle(IntegrationClient $client, MasterDataImporter $import): int
    {
        if (!config('integration.enabled')) {
            $this->info('INTEGRATION_ENABLED=false: nothing synced.');

            return self::SUCCESS;
        }

        $only = strtoupper((string) $this->option('only'));
        $tasks = [
            ['HR', '/employees', fn (array $item) => $import->employee($item)],
            ['HR', '/branches', fn (array $item) => $import->branch($item)],
            ['INV', '/products', fn (array $item) => $import->product($item)],
            ['INV', '/warehouses', fn (array $item) => $import->warehouse($item)],
        ];

        $ok = true;
        foreach ($tasks as [$system, $endpoint, $handler]) {
            if ($only && $only !== $system) {
                continue;
            }
            $ok = $this->syncList($client, $system, $endpoint, $handler) && $ok;
        }

        if (!$only || $only === 'INV') {
            $ok = $this->syncInvStock($client) && $ok;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function syncList(IntegrationClient $client, string $system, string $endpoint, callable $handler): bool
    {
        if (!$client->isConfigured($system)) {
            $this->warn("{$system}{$endpoint}: skipped ({$system}_API_URL is not set).");

            return true;
        }

        $cursor = IntegrationSyncCursor::firstOrNew(['resource' => $system . $endpoint]);
        $since = $this->option('full') ? null : $cursor->last_updated_at;
        $latest = $since;
        $count = 0;

        try {
            foreach ($this->pages($client, $system, $endpoint, array_filter(['updated_since' => $since])) as $items) {
                foreach ($items as $item) {
                    $handler($item);
                    $count++;
                    if (!empty($item['updated_at']) && (!$latest || Carbon::parse($item['updated_at'])->gt(Carbon::parse($latest)))) {
                        $latest = $item['updated_at'];
                    }
                }
            }
        } catch (Throwable $e) {
            $this->error("{$system}{$endpoint}: {$e->getMessage()}");

            return false;
        }

        $cursor->fill(['last_updated_at' => $latest, 'last_run_at' => now()])->save();
        $this->info("{$system}{$endpoint}: {$count} record(s) updated.");

        return true;
    }

    /**
     * "INV stock" ng bawat SKU (kabuuan ng lahat ng warehouse). Pang-display lang.
     */
    private function syncInvStock(IntegrationClient $client): bool
    {
        if (!$client->isConfigured('INV')) {
            return true;
        }

        try {
            $totals = [];
            foreach ($this->pages($client, 'INV', '/stock', []) as $items) {
                foreach ($items as $item) {
                    $totals[$item['sku']] = ($totals[$item['sku']] ?? 0) + (int) $item['quantity'];
                }
            }
        } catch (Throwable $e) {
            $this->error("INV/stock: {$e->getMessage()}");

            return false;
        }

        foreach ($totals as $sku => $quantity) {
            Product::where('sku', $sku)->update(['inv_stock' => $quantity, 'inv_stock_synced_at' => now()]);
        }
        $this->info('INV/stock: ' . count($totals) . ' SKU(s) updated.');

        return true;
    }

    /**
     * Binabasa ang lahat ng page ng isang listahan (B5: data.items + data.meta).
     */
    private function pages(IntegrationClient $client, string $system, string $endpoint, array $query): \Generator
    {
        $page = 1;
        do {
            $response = $client->get($system, $endpoint, $query + ['per_page' => 100, 'page' => $page]);
            if (!$response->successful()) {
                throw new RuntimeException("HTTP {$response->status()} " . ($response->json('message') ?? ''));
            }

            yield $response->json('data.items') ?? [];

            $lastPage = (int) ($response->json('data.meta.last_page') ?? 1);
            $page++;
        } while ($page <= $lastPage && $page <= self::MAX_PAGES);
    }
}
