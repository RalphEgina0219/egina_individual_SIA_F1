<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Models\CustomerFeedback;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Integration\MasterCodes;
use App\Support\IntegrationResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Buwanang ulat para sa ibang system.
 * - GET /staff-performance?period=YYYY-MM  (F8, para sa HR)
 * - GET /product-sales?period=YYYY-MM
 *
 * Ang "benta" ay bawat order na hindi Cancelled (ganito rin ang Sales
 * Reports ng CRM). Ang cancelled ay hindi kasama, kaya net na ito.
 */
class ReportController extends Controller
{
    public function staffPerformance(Request $request): JsonResponse
    {
        [$period, $start, $end] = $this->period($request);

        // Kung walang cashier_employee_no (lumang benta), gamitin ang employee_no ng user.
        $sales = Order::query()
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->where('orders.order_status', '!=', 'Cancelled')
            ->whereBetween('orders.order_date', [$start, $end])
            ->selectRaw('COALESCE(orders.cashier_employee_no, users.employee_no) as employee_no')
            ->selectRaw('SUM(orders.total_amount) as total_sales, COUNT(orders.id) as transaction_count')
            ->groupBy(DB::raw('COALESCE(orders.cashier_employee_no, users.employee_no)'))
            ->get()
            ->filter(fn ($row) => MasterCodes::isEmployee($row->employee_no))
            ->keyBy('employee_no');

        $feedback = CustomerFeedback::query()
            ->whereNotNull('employee_no')
            ->whereBetween('received_at', [$start, $end])
            ->get(['employee_no', 'type', 'rating'])
            ->groupBy('employee_no');

        $complaintTypes = config('shoplink.complaint_types');

        $items = $sales->keys()->merge($feedback->keys())->unique()->sort()->values()
            ->map(function (string $employeeNo) use ($sales, $feedback, $period, $complaintTypes) {
                $rows = $feedback->get($employeeNo, collect());
                $ratings = $rows->pluck('rating')->filter(fn ($r) => $r !== null);

                return [
                    'employee_no' => $employeeNo,
                    'period' => $period,
                    'total_sales' => IntegrationResponse::money($sales[$employeeNo]->total_sales ?? 0),
                    'transaction_count' => (int) ($sales[$employeeNo]->transaction_count ?? 0),
                    'complaint_count' => $rows->whereIn('type', $complaintTypes)->count(),
                    'average_rating' => $ratings->isEmpty() ? null : IntegrationResponse::money($ratings->avg()),
                ];
            });

        return IntegrationResponse::list($items, (int) $request->get('page', 1), (int) $request->get('per_page', 100));
    }

    public function productSales(Request $request): JsonResponse
    {
        [$period, $start, $end] = $this->period($request);

        $items = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.order_status', '!=', 'Cancelled')
            ->whereBetween('orders.order_date', [$start, $end])
            ->groupBy('products.sku')
            ->orderBy('products.sku')
            ->selectRaw('products.sku as sku, SUM(order_items.quantity) as quantity_sold')
            ->selectRaw('SUM(order_items.subtotal) as total_sales, COUNT(DISTINCT orders.id) as transaction_count')
            ->get()
            // Ang SKU-xxxx lang (galing INV) ang kilala ng ibang system.
            ->filter(fn ($row) => MasterCodes::isSku($row->sku))
            ->map(fn ($row) => [
                'sku' => $row->sku,
                'period' => $period,
                'quantity_sold' => (int) $row->quantity_sold,
                'total_sales' => IntegrationResponse::money($row->total_sales),
                'transaction_count' => (int) $row->transaction_count,
            ])
            ->values();

        return IntegrationResponse::list($items, (int) $request->get('page', 1), (int) $request->get('per_page', 100));
    }

    /**
     * Ang buwan ay sa oras ng Pilipinas; ang database ay naka-UTC.
     *
     * @return array{0: string, 1: Carbon, 2: Carbon}
     */
    private function period(Request $request): array
    {
        $request->validate([
            'period' => ['required', 'regex:' . MasterCodes::PERIOD],
        ], [
            'period.regex' => 'The period must be in YYYY-MM format, e.g. 2026-11.',
        ]);

        $period = $request->get('period');
        $tz = config('integration.timezone', 'Asia/Manila');
        $start = Carbon::createFromFormat('Y-m-d H:i:s', "{$period}-01 00:00:00", $tz);
        $end = $start->copy()->endOfMonth();

        return [$period, $start->setTimezone(config('app.timezone')), $end->setTimezone(config('app.timezone'))];
    }
}
