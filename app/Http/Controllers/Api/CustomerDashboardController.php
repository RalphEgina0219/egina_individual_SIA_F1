<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerFeedback;
use App\Models\CustomerMembership;
use App\Models\LoyaltyTransaction;
use App\Models\MembershipLevel;
use App\Models\Order;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Services\CustomerNotifier;
use App\Support\Permissions;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer Management dashboard, reports and reference options. Every figure
 * is computed from database records at request time.
 */
class CustomerDashboardController extends Controller
{
    private const OPEN_FEEDBACK = ['Pending', 'Under Review', 'Assigned', 'In Progress'];

    public function options(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok([
            'company' => config('shoplink.company'),
            'customer_statuses' => config('shoplink.customer_statuses'),
            'transacting_statuses' => config('shoplink.transacting_statuses'),
            'customer_types' => config('shoplink.customer_types'),
            'contact_methods' => config('shoplink.contact_methods'),
            'registration_channels' => config('shoplink.registration_channels'),
            'verification_methods' => config('shoplink.verification_methods'),
            'feedback_types' => config('shoplink.feedback_types'),
            'complaint_types' => config('shoplink.complaint_types'),
            'feedback_categories' => config('shoplink.feedback_categories'),
            'feedback_channels' => config('shoplink.feedback_channels'),
            'feedback_statuses' => config('shoplink.feedback_statuses'),
            'feedback_priorities' => config('shoplink.feedback_priorities'),
            'response_types' => \App\Models\FeedbackResponse::TYPES,
            'departments' => config('shoplink.departments'),
            'notification_types' => CustomerNotifier::TYPES,
            'roles' => Permissions::ROLES,
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'membership_levels' => MembershipLevel::orderBy('rank')->get(['id', 'name', 'code', 'rank', 'min_lifetime_points', 'points_multiplier', 'is_active']),
            'products' => \App\Models\Product::orderBy('product_name')->get(['id', 'product_name', 'sku']),
            // "Staff involved" (F8): mga empleyado galing HR, o users na may employee_no
            'employees' => \App\Models\Employee::orderBy('employee_no')->get(['employee_no', 'first_name', 'last_name', 'employment_status'])
                ->map(fn ($e) => ['employee_no' => $e->employee_no, 'name' => $e->full_name, 'status' => $e->employment_status])
                ->concat(User::whereNotNull('employee_no')->get(['employee_no', 'name'])->map(fn ($u) => ['employee_no' => $u->employee_no, 'name' => $u->name, 'status' => null]))
                ->unique('employee_no')->sortBy('employee_no')->values(),
            'issue_types' => \App\Services\Integration\MasterCodes::ISSUE_TYPES,
            'staff' => $user->can('feedback.assign')
                ? User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'role'])
                : [],
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        [$start, $end, $branchId] = $this->filters($request);

        $customers = fn () => Customer::query()->when($branchId, fn ($q) => $q->where('preferred_branch_id', $branchId));
        $memberships = fn () => CustomerMembership::query()->whereHas('customer', fn ($q) => $q->where('status', '!=', 'Archived')->when($branchId, fn ($q) => $q->where('preferred_branch_id', $branchId)));
        $ledger = fn () => LoyaltyTransaction::query()->whereBetween('created_at', [$start, $end])
            ->when($branchId, fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('preferred_branch_id', $branchId)));
        $feedback = fn () => CustomerFeedback::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $complaints = fn () => $feedback()->whereIn('type', config('shoplink.complaint_types'));

        $statusCounts = $customers()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $levelCounts = $memberships()->join('membership_levels', 'membership_levels.id', '=', 'customer_memberships.membership_level_id')
            ->selectRaw('membership_levels.code, membership_levels.name, membership_levels.`rank`, COUNT(*) as total')
            ->groupBy('membership_levels.code', 'membership_levels.name', 'membership_levels.rank')
            ->orderBy('membership_levels.rank')
            ->get();

        $vipCustomers = $customers()->where('status', '!=', 'Archived')
            ->where(fn ($q) => $q->where('status', 'VIP')->orWhereHas('membership.level', fn ($l) => $l->where('code', MembershipLevel::VIP)))
            ->count();

        $issued = (int) $ledger()->where(fn ($q) => $q->where('type', LoyaltyTransaction::EARN)->orWhere(fn ($q) => $q->where('type', LoyaltyTransaction::ADJUSTMENT)->where('points', '>', 0)))->sum('points');
        $redeemed = abs((int) $ledger()->where('type', LoyaltyTransaction::REDEEM)->sum('points')) - (int) $ledger()->where('type', LoyaltyTransaction::REFUND)->sum('points');

        $stats = [
            'total_customers' => $customers()->where('status', '!=', 'Archived')->count(),
            'active_customers' => (int) (($statusCounts['Active'] ?? 0) + ($statusCounts['VIP'] ?? 0)),
            'inactive_customers' => (int) ($statusCounts['Inactive'] ?? 0),
            'pending_verification' => (int) ($statusCounts['Pending Verification'] ?? 0),
            'loyalty_members' => $memberships()->whereNotNull('loyalty_enrolled_at')->count(),
            'gold_members' => (int) ($levelCounts->firstWhere('code', MembershipLevel::GOLD)->total ?? 0),
            'vip_customers' => $vipCustomers,
            'points_issued' => $issued,
            'points_redeemed' => $redeemed,
            'points_outstanding' => (int) $memberships()->sum('points_balance'),
            'total_feedback' => $feedback()->whereBetween('received_at', [$start, $end])->count(),
            'pending_complaints' => $complaints()->whereIn('status', self::OPEN_FEEDBACK)->count(),
            'resolved_complaints' => $complaints()->whereIn('status', ['Resolved', 'Closed'])->whereBetween('received_at', [$start, $end])->count(),
            'new_registrations' => $customers()->whereBetween('created_at', [$start, $end])->count(),
        ];

        $granularity = $start->diffInDays($end) > 92 ? 'month' : 'day';

        $registrationTrend = $this->series(
            $customers()->whereBetween('created_at', [$start, $end]),
            'created_at', $start, $end, $granularity, ['COUNT(*) as registrations']
        );

        $purchaseActivity = $this->series(
            Order::query()->where('order_status', '!=', 'Cancelled')->whereBetween('order_date', [$start, $end])->when($branchId, fn ($q) => $q->where('branch_id', $branchId)),
            'order_date', $start, $end, $granularity,
            [
                'SUM(CASE WHEN customer_id IS NOT NULL THEN 1 ELSE 0 END) as member_transactions',
                'SUM(CASE WHEN customer_id IS NULL THEN 1 ELSE 0 END) as walk_in_transactions',
                'SUM(CASE WHEN customer_id IS NOT NULL THEN total_amount ELSE 0 END) as member_sales',
            ]
        );

        $loyaltyActivity = $this->series(
            $ledger(), 'created_at', $start, $end, $granularity,
            [
                "SUM(CASE WHEN type = 'earn' OR (type = 'adjustment' AND points > 0) THEN points ELSE 0 END) as earned",
                "SUM(CASE WHEN type = 'redeem' THEN -points WHEN type = 'refund' THEN -points ELSE 0 END) as redeemed",
            ]
        );

        return $this->ok([
            'filters' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'branch_id' => $branchId, 'granularity' => $granularity],
            'stats' => $stats,
            'charts' => [
                'registration_trend' => $registrationTrend,
                'membership_distribution' => $levelCounts->map(fn ($l) => ['name' => $l->name, 'value' => (int) $l->total])->values(),
                'status_distribution' => collect(config('shoplink.customer_statuses'))->map(fn ($s) => ['name' => $s, 'value' => (int) ($statusCounts[$s] ?? 0)])->values(),
                'purchase_activity' => $purchaseActivity,
                'loyalty_activity' => $loyaltyActivity,
                'feedback_categories' => $feedback()->whereBetween('received_at', [$start, $end])
                    ->selectRaw("COALESCE(category, 'Uncategorized') as name, COUNT(*) as value")
                    ->groupBy(DB::raw("COALESCE(category, 'Uncategorized')"))
                    ->orderByDesc('value')->get(),
                'feedback_types' => $feedback()->whereBetween('received_at', [$start, $end])
                    ->selectRaw('type as name, COUNT(*) as value')->groupBy('type')->orderByDesc('value')->get(),
                'complaint_status' => collect(config('shoplink.feedback_statuses'))->map(fn ($s) => [
                    'name' => $s,
                    'value' => $complaints()->where('status', $s)->count(),
                ])->values(),
            ],
            'recent_registrations' => $customers()->with('membership.level:id,name')->latest('id')->limit(8)
                ->get(['id', 'customer_code', 'full_name', 'status', 'registration_channel', 'created_at'])
                ->map(fn ($c) => array_merge($c->only(['id', 'customer_code', 'full_name', 'status', 'registration_channel', 'created_at']), ['membership_level' => $c->membership?->level?->name])),
            'recent_transactions' => Order::with(['customer:id,customer_code,full_name', 'branch:id,name'])
                ->whereNotNull('customer_id')
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->latest('order_date')->limit(8)
                ->get(['id', 'transaction_number', 'customer_id', 'branch_id', 'order_date', 'total_amount', 'order_status']),
        ]);
    }

    public function reports(Request $request): JsonResponse
    {
        [$start, $end, $branchId] = $this->filters($request);
        $inactiveDays = max(1, min((int) $request->get('inactive_days', 90), 3650));

        $customers = fn () => Customer::query()->where('status', '!=', 'Archived')->when($branchId, fn ($q) => $q->where('preferred_branch_id', $branchId));
        $orders = fn () => Order::query()->where('order_status', '!=', 'Cancelled')->whereBetween('order_date', [$start, $end])->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $inactiveQuery = fn () => $customers()->whereIn('status', ['Active', 'VIP', 'Verified'])
            ->whereDoesntHave('orders', fn ($o) => $o->where('order_date', '>=', now()->subDays($inactiveDays)));

        $topCustomers = $orders()->whereNotNull('customer_id')
            ->selectRaw('customer_id, COUNT(*) as transactions, SUM(total_amount) as total_spent, MAX(order_date) as last_purchase_at')
            ->groupBy('customer_id')->orderByDesc('total_spent')->limit(10)->get();
        $topCustomerModels = Customer::with('membership.level:id,name')->whereIn('id', $topCustomers->pluck('customer_id'))->get()->keyBy('id');

        $buyers = $orders()->whereNotNull('customer_id')->distinct()->pluck('customer_id');
        $returning = Order::whereIn('customer_id', $buyers)->where('order_status', '!=', 'Cancelled')->where('order_date', '<', $start)->distinct()->count('customer_id');

        $resolved = CustomerFeedback::whereNotNull('resolved_at')->whereBetween('received_at', [$start, $end])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->get(['received_at', 'resolved_at']);

        return $this->ok([
            'filters' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'branch_id' => $branchId, 'inactive_days' => $inactiveDays],
            'segmentation' => [
                'by_type' => $customers()->selectRaw('customer_type as name, COUNT(*) as value')->groupBy('customer_type')->get(),
                'by_status' => $customers()->selectRaw('status as name, COUNT(*) as value')->groupBy('status')->get(),
                'by_contact_method' => $customers()->selectRaw('preferred_contact_method as name, COUNT(*) as value')->groupBy('preferred_contact_method')->get(),
                'by_branch' => $customers()->leftJoin('branches', 'branches.id', '=', 'customers.preferred_branch_id')
                    ->selectRaw("COALESCE(branches.name, 'No preference') as name, COUNT(*) as value")
                    ->groupBy(DB::raw("COALESCE(branches.name, 'No preference')"))->get(),
                'by_level' => CustomerMembership::join('membership_levels', 'membership_levels.id', '=', 'customer_memberships.membership_level_id')
                    ->whereIn('customer_id', $customers()->select('id'))
                    ->selectRaw('membership_levels.name as name, COUNT(*) as value')
                    ->groupBy('membership_levels.name', 'membership_levels.rank')->orderBy('membership_levels.rank')->get(),
            ],
            'activity' => [
                'purchasing_customers' => $buyers->count(),
                'returning_customers' => $returning,
                'new_purchasing_customers' => $buyers->count() - $returning,
                'member_sales' => round((float) $orders()->whereNotNull('customer_id')->sum('total_amount'), 2),
                'walk_in_sales' => round((float) $orders()->whereNull('customer_id')->sum('total_amount'), 2),
                'member_transactions' => $orders()->whereNotNull('customer_id')->count(),
                'walk_in_transactions' => $orders()->whereNull('customer_id')->count(),
            ],
            'top_customers' => $topCustomers->map(fn ($row) => [
                'id' => $row->customer_id,
                'customer_code' => $topCustomerModels[$row->customer_id]->customer_code ?? null,
                'full_name' => $topCustomerModels[$row->customer_id]->full_name ?? null,
                'membership_level' => $topCustomerModels[$row->customer_id]?->membership?->level?->name,
                'transactions' => (int) $row->transactions,
                'total_spent' => round((float) $row->total_spent, 2),
                'last_purchase_at' => $row->last_purchase_at,
            ]),
            'inactive_customers' => [
                'count' => $inactiveQuery()->count(),
                'list' => $inactiveQuery()->withMax('orders as last_purchase_at', 'order_date')
                    ->orderBy('last_purchase_at')->limit(25)
                    ->get(['id', 'customer_code', 'full_name', 'phone', 'status', 'created_at']),
            ],
            'birthdays_this_month' => $customers()->whereIn('status', config('shoplink.transacting_statuses'))
                ->whereNotNull('birthday')
                ->get(['id', 'customer_code', 'full_name', 'birthday', 'email', 'preferred_contact_method'])
                ->filter(fn ($c) => $c->birthday->month === now()->month)
                ->sortBy(fn ($c) => $c->birthday->day)->values(),
            'loyalty' => [
                'points_outstanding' => (int) CustomerMembership::whereIn('customer_id', $customers()->select('id'))->sum('points_balance'),
                'ledger' => LoyaltyTransaction::whereBetween('created_at', [$start, $end])
                    ->selectRaw('type, COUNT(*) as transactions, SUM(points) as points')->groupBy('type')->get(),
                'top_rewards' => RewardRedemption::with('reward:id,name')
                    ->where('status', 'Completed')->whereBetween('created_at', [$start, $end])
                    ->selectRaw('reward_id, SUM(quantity) as quantity, SUM(points_used) as points')
                    ->groupBy('reward_id')->orderByDesc('quantity')->limit(5)->get(),
            ],
            'feedback' => [
                'average_rating' => round((float) CustomerFeedback::whereBetween('received_at', [$start, $end])->whereNotNull('rating')->avg('rating'), 2),
                'by_channel' => CustomerFeedback::whereBetween('received_at', [$start, $end])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                    ->selectRaw('channel as name, COUNT(*) as value')->groupBy('channel')->get(),
                'by_department' => CustomerFeedback::whereBetween('received_at', [$start, $end])->whereNotNull('department')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                    ->selectRaw('department as name, COUNT(*) as value')->groupBy('department')->get(),
                'resolved_count' => $resolved->count(),
                'average_resolution_hours' => $resolved->isEmpty() ? null
                    : round($resolved->avg(fn ($f) => $f->received_at->diffInMinutes($f->resolved_at) / 60), 1),
            ],
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: ?int}
     */
    private function filters(Request $request): array
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $end = $request->end_date ? Carbon::parse($request->end_date)->endOfDay() : now()->endOfDay();
        $start = $request->start_date ? Carbon::parse($request->start_date)->startOfDay() : $end->copy()->subDays(29)->startOfDay();

        return [$start, $end, $request->branch_id ? (int) $request->branch_id : null];
    }

    /**
     * Group a query by day or month and fill empty periods with zeros.
     */
    private function series(Builder $query, string $column, Carbon $start, Carbon $end, string $granularity, array $aggregates): array
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $period = match (true) {
            $granularity === 'month' && $sqlite => "strftime('%Y-%m', {$column})",
            $granularity === 'month' => "DATE_FORMAT({$column}, '%Y-%m')",
            default => "DATE({$column})",
        };

        $rows = $query->selectRaw("{$period} as period, " . implode(', ', $aggregates))
            ->groupBy(DB::raw($period))
            ->get()
            ->keyBy('period');

        $keys = collect(preg_replace('/^.* as /', '', $aggregates));
        $format = $granularity === 'month' ? 'Y-m' : 'Y-m-d';
        $periods = CarbonPeriod::create($start->copy()->startOf($granularity), "1 {$granularity}", $end);

        return collect($periods)->map(function (Carbon $date) use ($rows, $keys, $format) {
            $row = $rows->get($date->format($format));

            return array_merge(
                ['period' => $date->format($format)],
                $keys->mapWithKeys(fn ($k) => [$k => $row ? round((float) $row->{$k}, 2) : 0])->all()
            );
        })->values()->all();
    }
}
