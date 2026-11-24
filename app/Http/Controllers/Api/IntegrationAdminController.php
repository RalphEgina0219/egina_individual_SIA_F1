<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\CustomerFeedback;
use App\Models\Employee;
use App\Models\IntegrationLog;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationSyncCursor;
use App\Models\Order;
use App\Services\Integration\IntegrationClient;
use App\Services\Integration\Outbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Settings → Integrations (Admin lang): health ng bawat system, outbox
 * na may Retry, ang huling 100 na logs, at ang mga benta na failed ang stock sync.
 */
class IntegrationAdminController extends Controller
{
    private const SYSTEMS = ['PROC', 'INV', 'HR'];

    public function summary(): JsonResponse
    {
        return $this->ok([
            'system_code' => config('integration.system_code'),
            'enabled' => (bool) config('integration.enabled'),
            'api_key_set' => (string) config('integration.api_key') !== '',
            'systems' => collect(self::SYSTEMS)->mapWithKeys(fn ($s) => [$s => [
                'url' => config("integration.systems.{$s}.url") ?: null,
                'key_set' => (string) config("integration.systems.{$s}.key") !== '',
            ]]),
            'outbox_counts' => IntegrationOutbox::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'failed_sales' => Order::where('stock_sync_status', 'failed')->latest('id')->limit(20)
                ->get(['id', 'transaction_number', 'order_date', 'total_amount', 'order_status', 'stock_sync_status']),
            'failed_forwards' => CustomerFeedback::where('forward_status', 'failed')->latest('id')->limit(20)
                ->get(['id', 'feedback_number', 'subject', 'sku', 'forward_status']),
            'employees_count' => Employee::count(),
            'sync_cursors' => IntegrationSyncCursor::orderBy('resource')->get(['resource', 'last_updated_at', 'last_run_at']),
        ]);
    }

    public function health(IntegrationClient $client): JsonResponse
    {
        $results = ['CRM' => ['ok' => true, 'configured' => true, 'message' => 'This system']];
        foreach (self::SYSTEMS as $system) {
            $results[$system] = $client->health($system);
        }

        return $this->ok($results);
    }

    public function outbox(Request $request): JsonResponse
    {
        $rows = IntegrationOutbox::query()
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->target_system, fn ($q, $v) => $q->where('target_system', $v))
            ->when($request->search, fn ($q, $v) => $q->where('reference_number', 'like', "%{$v}%"))
            ->latest('id')
            ->paginate($this->perPage($request, 25));

        return $this->ok($rows);
    }

    public function retry(IntegrationOutbox $outbox): JsonResponse
    {
        if ($outbox->status !== IntegrationOutbox::STATUS_FAILED) {
            throw new BusinessRuleException('Only failed rows can be retried.');
        }

        Outbox::retry($outbox);

        return $this->ok($outbox->fresh(), "{$outbox->reference_number} is pending again and will be sent on the next run.");
    }

    public function processNow(): JsonResponse
    {
        Artisan::call('integration:process-outbox');

        return $this->ok(['output' => trim(Artisan::output())], 'Outbox processed');
    }

    public function logs(Request $request): JsonResponse
    {
        $logs = IntegrationLog::query()
            ->when($request->direction, fn ($q, $v) => $q->where('direction', $v))
            ->when($request->system, fn ($q, $v) => $q->where('system', $v))
            ->latest('id')
            ->limit(100)
            ->get();

        return $this->ok($logs);
    }

    public function employees(): JsonResponse
    {
        return $this->ok(Employee::orderBy('employee_no')->get());
    }
}
