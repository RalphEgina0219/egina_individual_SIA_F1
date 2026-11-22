<?php

namespace Tests\Feature;

use App\Models\CustomerFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CustomerManagementHelpers;
use Tests\TestCase;

/**
 * Module 4: customer feedback and complaint management.
 */
class FeedbackComplaintTest extends TestCase
{
    use RefreshDatabase, CustomerManagementHelpers;

    private function recordComplaint(int $customerId): int
    {
        return $this->postJson('/api/feedback', [
            'customer_id' => $customerId,
            'type' => 'Complaint',
            'channel' => 'In-store',
            'subject' => 'Expired product on shelf',
            'message' => 'Found expired milk in aisle 3.',
            'rating' => 2,
        ])->assertCreated()->json('data.id');
    }

    public function test_complete_complaint_workflow(): void
    {
        $customer = $this->activeCustomer();
        $warehouseStaff = $this->userWithRole('Warehouse');

        // 1-3: record
        $this->actingAsRole('Customer Service');
        $id = $this->recordComplaint($customer->id);
        $feedback = CustomerFeedback::find($id);
        $this->assertSame('Pending', $feedback->status);
        $this->assertStringStartsWith('FB-', $feedback->reference_number);
        $this->assertDatabaseHas('customer_notifications', ['customer_id' => $customer->id, 'type' => 'feedback_received']);

        // 4: categorize
        $this->putJson("/api/feedback/{$id}", ['category' => 'Product Quality', 'priority' => 'High'])
            ->assertOk()->assertJsonPath('data.status', 'Under Review');

        // 5: assign (manager only)
        $this->postJson("/api/feedback/{$id}/assign", ['department' => 'Inventory / Warehouse'])->assertForbidden();
        $this->actingAsRole('Manager');
        $this->postJson("/api/feedback/{$id}/assign", ['department' => 'Inventory / Warehouse', 'assigned_to' => $warehouseStaff->id])
            ->assertOk()->assertJsonPath('data.status', 'Assigned');

        // 6: assigned staff investigates even without feedback permissions
        $this->actingAs($warehouseStaff, 'sanctum');
        $this->getJson('/api/feedback')->assertOk()->assertJsonPath('data.feedback.total', 1);
        $this->postJson("/api/feedback/{$id}/responses", ['response_type' => 'Investigation', 'message' => 'Pulled expired stock.'])
            ->assertCreated();
        $this->assertSame('In Progress', $feedback->fresh()->status);

        // Unrelated staff cannot see it
        $this->actingAsRole('Sales');
        $this->getJson("/api/feedback/{$id}")->assertForbidden();
        $this->getJson('/api/feedback')->assertJsonPath('data.feedback.total', 0);

        // Cannot close an unresolved complaint
        $this->actingAsRole('Customer Service');
        $this->postJson("/api/feedback/{$id}/status", ['status' => 'Closed'])->assertUnprocessable();
        $this->postJson("/api/feedback/{$id}/status", ['status' => 'Resolved'])->assertUnprocessable();

        // 7: resolution
        $this->postJson("/api/feedback/{$id}/responses", ['response_type' => 'Resolution', 'message' => 'Stock rotation checks added.'])
            ->assertCreated();
        $this->assertSame('Resolved', $feedback->fresh()->status);
        $this->assertNotNull($feedback->fresh()->resolved_at);

        // 8: communicate to customer
        $this->postJson("/api/feedback/{$id}/responses", ['response_type' => 'Customer Communication', 'message' => 'Called customer with apology.'])
            ->assertJsonValidationErrors('communicated_via');
        $this->postJson("/api/feedback/{$id}/responses", ['response_type' => 'Customer Communication', 'message' => 'Emailed resolution.', 'communicated_via' => 'Email'])
            ->assertCreated();
        $this->assertDatabaseHas('customer_notifications', ['customer_id' => $customer->id, 'type' => 'complaint_status']);

        // 9: close
        $this->postJson("/api/feedback/{$id}/status", ['status' => 'Closed'])->assertOk();
        $this->postJson("/api/feedback/{$id}/responses", ['response_type' => 'Internal Note', 'message' => 'late note'])->assertUnprocessable();

        // Response history is kept
        $this->getJson("/api/feedback/{$id}")->assertOk()->assertJsonCount(1, 'data.assignments')
            ->assertJsonPath('data.status', 'Closed');
        $this->assertGreaterThanOrEqual(6, $feedback->responses()->count());
    }

    public function test_anonymous_feedback_requires_contact_name(): void
    {
        $this->actingAsRole('Customer Service');

        $this->postJson('/api/feedback', [
            'type' => 'Suggestion', 'channel' => 'Website', 'subject' => 'More parking', 'message' => 'Please add parking.',
        ])->assertJsonValidationErrors('contact_name');

        $this->postJson('/api/feedback', [
            'type' => 'Suggestion', 'channel' => 'Email', 'subject' => 'More parking', 'message' => 'Please add parking.',
            'contact_name' => 'Walk-in shopper',
        ])->assertCreated();
    }

    public function test_feedback_listing_filters_and_status_counts(): void
    {
        $customer = $this->activeCustomer();
        $this->actingAsRole('Customer Service');
        $this->recordComplaint($customer->id);
        $this->postJson('/api/feedback', [
            'customer_id' => $customer->id, 'type' => 'General Feedback', 'channel' => 'Website',
            'subject' => 'Nice store', 'message' => 'Clean aisles.', 'rating' => 5,
        ])->assertCreated();

        $this->getJson('/api/feedback?complaints_only=1')->assertOk()->assertJsonPath('data.feedback.total', 1);
        $this->getJson('/api/feedback?channel=Website')->assertJsonPath('data.feedback.total', 1);
        $this->getJson('/api/feedback')->assertJsonPath('data.status_counts.Pending', 2);
    }
}
