<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFeedbackRequest;
use App\Models\ComplaintAssignment;
use App\Models\CustomerFeedback;
use App\Models\FeedbackResponse;
use App\Models\User;
use App\Services\CustomerNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Customer feedback and complaint management (Module 4). Feedback from all
 * channels (in-store, website, e-mail, phone, social media) is recorded here
 * by staff; external channels are not synchronized automatically.
 */
class FeedbackController extends Controller
{
    private const OPEN_STATUSES = ['Pending', 'Under Review', 'Assigned', 'In Progress'];

    public function __construct(private CustomerNotifier $notifier)
    {
    }

    /**
     * Staff with feedback.view see everything; other users only see the
     * concerns assigned to them for investigation.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $base = CustomerFeedback::query()
            ->when(!$user->can('feedback.view') || $request->boolean('assigned_to_me'), fn ($q) => $q->where('assigned_to', $user->id))
            ->when($request->boolean('complaints_only'), fn ($q) => $q->whereIn('type', config('shoplink.complaint_types')))
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->category, fn ($q, $v) => $q->where('category', $v))
            ->when($request->channel, fn ($q, $v) => $q->where('channel', $v))
            ->when($request->priority, fn ($q, $v) => $q->where('priority', $v))
            ->when($request->department, fn ($q, $v) => $q->where('department', $v))
            ->when($request->branch_id, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($request->customer_id, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->start_date, fn ($q, $v) => $q->whereDate('received_at', '>=', $v))
            ->when($request->end_date, fn ($q, $v) => $q->whereDate('received_at', '<=', $v))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('reference_number', 'like', "%{$s}%")
                ->orWhere('subject', 'like', "%{$s}%")
                ->orWhere('contact_name', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->search($s))));

        $statusCounts = (clone $base)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $feedback = (clone $base)
            ->with(['customer:id,customer_code,full_name', 'assignee:id,name', 'branch:id,name', 'recorder:id,name'])
            ->when($request->status === 'open', fn ($q) => $q->whereIn('status', self::OPEN_STATUSES))
            ->when($request->status && $request->status !== 'open', fn ($q) => $q->where('status', $request->status))
            ->orderByRaw("CASE priority WHEN 'Urgent' THEN 1 WHEN 'High' THEN 2 WHEN 'Medium' THEN 3 ELSE 4 END")
            ->orderByDesc('received_at')
            ->paginate($this->perPage($request, 15));

        return $this->ok(['feedback' => $feedback, 'status_counts' => $statusCounts]);
    }

    public function store(StoreFeedbackRequest $request): JsonResponse
    {
        $data = $request->validated();

        $feedback = DB::transaction(function () use ($data, $request) {
            $feedback = CustomerFeedback::create(array_merge($data, [
                'priority' => $data['priority'] ?? 'Medium',
                'received_at' => $data['received_at'] ?? now(),
                'recorded_by' => $request->user()->id,
                'status' => 'Pending',
            ]));

            FeedbackResponse::create([
                'customer_feedback_id' => $feedback->id,
                'user_id' => $request->user()->id,
                'response_type' => 'Status Update',
                'message' => "Recorded from {$feedback->channel}.",
                'status_to' => 'Pending',
            ]);

            return $feedback;
        });

        $feedback->refresh();

        if ($feedback->customer) {
            $this->notifier->send($feedback->customer, 'feedback_received', "We received your feedback ({$feedback->reference_number})", "Thank you for contacting ShopLink. Your {$feedback->type} \"{$feedback->subject}\" was received and recorded under reference {$feedback->reference_number}.");
        }

        return $this->ok($feedback->load(['customer:id,customer_code,full_name']), 'Feedback recorded successfully', 201);
    }

    public function show(Request $request, CustomerFeedback $feedback): JsonResponse
    {
        $this->authorizeAccess($request->user(), $feedback);

        return $this->ok($feedback->load([
            'customer:id,customer_code,full_name,phone,email,preferred_contact_method',
            'branch:id,name',
            'order:id,transaction_number,order_date,total_amount',
            'product:id,product_name,sku',
            'assignee:id,name,role',
            'recorder:id,name',
            'assignments.assignee:id,name',
            'assignments.assigner:id,name',
            'responses.user:id,name,role',
        ]));
    }

    /**
     * Categorize / re-prioritize the concern (workflow step 4).
     */
    public function update(Request $request, CustomerFeedback $feedback): JsonResponse
    {
        $data = $request->validate([
            'type' => ['sometimes', 'required', Rule::in(config('shoplink.feedback_types'))],
            'category' => ['sometimes', 'nullable', Rule::in(config('shoplink.feedback_categories'))],
            'priority' => ['sometimes', 'required', Rule::in(config('shoplink.feedback_priorities'))],
        ]);

        $this->guardNotClosed($feedback);

        DB::transaction(function () use ($feedback, $data, $request) {
            $changes = collect($data)->filter(fn ($v, $k) => $feedback->{$k} !== $v)->map(fn ($v, $k) => "{$k}: " . ($feedback->{$k} ?? 'none') . " -> {$v}");
            $wasPending = $feedback->status === 'Pending';

            $feedback->update(array_merge($data, $wasPending ? ['status' => 'Under Review'] : []));

            if ($changes->isNotEmpty() || $wasPending) {
                FeedbackResponse::create([
                    'customer_feedback_id' => $feedback->id,
                    'user_id' => $request->user()->id,
                    'response_type' => 'Status Update',
                    'message' => 'Categorized. ' . $changes->implode('; '),
                    'status_from' => $wasPending ? 'Pending' : null,
                    'status_to' => $wasPending ? 'Under Review' : null,
                ]);
            }
        });

        return $this->ok($feedback->fresh(), 'Feedback updated');
    }

    /**
     * Assign to a department / staff member (workflow step 5).
     */
    public function assign(Request $request, CustomerFeedback $feedback): JsonResponse
    {
        $data = $request->validate([
            'department' => ['required', Rule::in(config('shoplink.departments'))],
            'assigned_to' => 'nullable|integer|exists:users,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $this->guardNotClosed($feedback);
        if (isset($data['assigned_to']) && !User::whereKey($data['assigned_to'])->where('is_active', true)->exists()) {
            throw new BusinessRuleException('The selected staff member is inactive.');
        }

        DB::transaction(function () use ($feedback, $data, $request) {
            $feedback->assignments()->update(['is_current' => false]);

            ComplaintAssignment::create([
                'customer_feedback_id' => $feedback->id,
                'department' => $data['department'],
                'assigned_to' => $data['assigned_to'] ?? null,
                'assigned_by' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
                'is_current' => true,
                'assigned_at' => now(),
            ]);

            $from = $feedback->status;
            $to = in_array($from, ['Pending', 'Under Review'], true) ? 'Assigned' : $from;

            $feedback->update([
                'department' => $data['department'],
                'assigned_to' => $data['assigned_to'] ?? null,
                'status' => $to,
            ]);

            $assignee = isset($data['assigned_to']) ? User::find($data['assigned_to'])->name : 'department queue';
            FeedbackResponse::create([
                'customer_feedback_id' => $feedback->id,
                'user_id' => $request->user()->id,
                'response_type' => 'Status Update',
                'message' => "Assigned to {$data['department']} ({$assignee})." . (!empty($data['notes']) ? " {$data['notes']}" : ''),
                'status_from' => $from !== $to ? $from : null,
                'status_to' => $from !== $to ? $to : null,
            ]);
        });

        return $this->ok($feedback->fresh(['assignee:id,name']), 'Feedback assigned');
    }

    /**
     * Record investigation notes, resolution, or customer communication
     * (workflow steps 6-9), optionally moving the status.
     */
    public function respond(Request $request, CustomerFeedback $feedback): JsonResponse
    {
        $this->authorizeAccess($request->user(), $feedback, true);

        $data = $request->validate([
            'response_type' => ['required', Rule::in(FeedbackResponse::TYPES)],
            'message' => 'required|string|max:5000',
            'status' => ['nullable', Rule::in(config('shoplink.feedback_statuses'))],
            'communicated_via' => ['nullable', 'required_if:response_type,Customer Communication', Rule::in(array_merge(config('shoplink.contact_methods'), config('shoplink.feedback_channels')))],
            'send_email' => 'sometimes|boolean',
        ]);

        $this->guardNotClosed($feedback);

        $newStatus = $data['status'] ?? match ($data['response_type']) {
            'Resolution' => 'Resolved',
            'Investigation' => in_array($feedback->status, ['Pending', 'Under Review', 'Assigned'], true) ? 'In Progress' : null,
            default => null,
        };
        if ($newStatus === $feedback->status) {
            $newStatus = null;
        }
        if ($newStatus) {
            $this->guardTransition($feedback, $newStatus, $data['response_type'] === 'Resolution' ? $data['message'] : null);
        }

        $response = DB::transaction(function () use ($feedback, $data, $newStatus, $request) {
            $response = FeedbackResponse::create([
                'customer_feedback_id' => $feedback->id,
                'user_id' => $request->user()->id,
                'response_type' => $data['response_type'],
                'message' => $data['message'],
                'status_from' => $newStatus ? $feedback->status : null,
                'status_to' => $newStatus,
                'communicated_via' => $data['communicated_via'] ?? null,
            ]);

            $updates = [];
            if ($data['response_type'] === 'Resolution') {
                $updates['resolution_summary'] = $data['message'];
            }
            if ($data['response_type'] === 'Customer Communication') {
                $updates['customer_notified_at'] = now();
            }
            if ($newStatus) {
                $updates = array_merge($updates, $this->statusTimestamps($newStatus));
            }
            if ($updates) {
                $feedback->update($updates);
            }

            return $response;
        });

        if ($feedback->customer && ($request->boolean('send_email') || $data['response_type'] === 'Customer Communication' && ($data['communicated_via'] ?? null) === 'Email')) {
            $this->notifier->send($feedback->customer, 'complaint_status', "Update on your concern {$feedback->reference_number}", "Status: {$feedback->fresh()->status}\n{$data['message']}");
        }

        return $this->ok($response->load('user:id,name'), 'Response recorded', 201);
    }

    public function changeStatus(Request $request, CustomerFeedback $feedback): JsonResponse
    {
        $this->authorizeAccess($request->user(), $feedback, true);

        $data = $request->validate([
            'status' => ['required', Rule::in(config('shoplink.feedback_statuses'))],
            'note' => 'nullable|string|max:1000',
            'notify_customer' => 'sometimes|boolean',
        ]);

        if ($data['status'] === $feedback->status) {
            throw new BusinessRuleException("Feedback is already {$data['status']}.");
        }
        if ($feedback->status === 'Closed' && !$request->user()->can('feedback.assign')) {
            throw new BusinessRuleException('Only a manager can reopen closed feedback.', 403);
        }
        $this->guardTransition($feedback, $data['status']);

        DB::transaction(function () use ($feedback, $data, $request) {
            FeedbackResponse::create([
                'customer_feedback_id' => $feedback->id,
                'user_id' => $request->user()->id,
                'response_type' => 'Status Update',
                'message' => ($data['note'] ?? null) ?: "Status changed to {$data['status']}.",
                'status_from' => $feedback->status,
                'status_to' => $data['status'],
            ]);

            $feedback->update($this->statusTimestamps($data['status']));
        });

        if ($feedback->customer && $request->boolean('notify_customer')) {
            $this->notifier->send($feedback->customer, 'complaint_status', "Update on your concern {$feedback->reference_number}", "Your {$feedback->type} is now: {$data['status']}." . (!empty($data['note']) ? "\n{$data['note']}" : ''));
        }

        return $this->ok($feedback->fresh(), "Status changed to {$data['status']}");
    }

    private function statusTimestamps(string $status): array
    {
        return match ($status) {
            'Resolved' => ['status' => $status, 'resolved_at' => now()],
            'Closed' => ['status' => $status, 'closed_at' => now()],
            default => ['status' => $status, 'resolved_at' => null, 'closed_at' => null],
        };
    }

    private function guardTransition(CustomerFeedback $feedback, string $status, ?string $resolution = null): void
    {
        if ($status === 'Resolved' && !$feedback->resolution_summary && !$resolution) {
            throw new BusinessRuleException('Record a Resolution response before marking the feedback as Resolved.');
        }
        if ($status === 'Closed' && $feedback->is_complaint && $feedback->status !== 'Resolved') {
            throw new BusinessRuleException('Complaints must be Resolved before they can be Closed.');
        }
        if ($status === 'Assigned' && !$feedback->department) {
            throw new BusinessRuleException('Use Assign to route the concern to a department.');
        }
    }

    private function guardNotClosed(CustomerFeedback $feedback): void
    {
        if ($feedback->status === 'Closed') {
            throw new BusinessRuleException('This feedback is closed. Reopen it before making changes.');
        }
    }

    private function authorizeAccess(User $user, CustomerFeedback $feedback, bool $write = false): void
    {
        $allowed = $user->can($write ? 'feedback.respond' : 'feedback.view') || $feedback->assigned_to === $user->id;

        if (!$allowed) {
            throw new BusinessRuleException('Unauthorized. Insufficient permissions.', 403);
        }
    }
}
