<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Personal details (birthday, address, notes, verification method) are only
 * returned to roles holding customers.view_sensitive.
 *
 * @mixin \App\Models\Customer
 */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sensitive = $request->user()?->can('customers.view_sensitive') ?? false;
        $membership = $this->whenLoaded('membership');

        return [
            'id' => $this->id,
            'customer_code' => $this->customer_code,
            'membership_number' => $this->membership_number,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->when($sensitive, $this->address),
            'birthday' => $this->when($sensitive, fn () => $this->birthday?->format('Y-m-d')),
            'preferred_branch_id' => $this->preferred_branch_id,
            'preferred_branch' => $this->whenLoaded('preferredBranch', fn () => $this->preferredBranch?->only(['id', 'code', 'name'])),
            'preferred_contact_method' => $this->preferred_contact_method,
            'customer_type' => $this->customer_type,
            'status' => $this->status,
            'status_reason' => $this->status_reason,
            'status_changed_at' => $this->status_changed_at,
            'registration_date' => $this->registration_date?->format('Y-m-d'),
            'registration_channel' => $this->registration_channel,
            'registered_by' => $this->whenLoaded('registeredBy', fn () => $this->registeredBy?->only(['id', 'name'])),
            'verified_at' => $this->verified_at,
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy?->only(['id', 'name'])),
            'verification_method' => $this->when($sensitive, $this->verification_method),
            'activated_at' => $this->activated_at,
            'notes' => $this->when($sensitive, $this->notes),
            'can_transact' => $this->canTransact(),
            'membership' => $membership instanceof \App\Models\CustomerMembership ? [
                'level' => $membership->level?->only(['id', 'name', 'code', 'rank']),
                'is_loyalty_member' => $membership->is_loyalty_member,
                'loyalty_enrolled_at' => $membership->loyalty_enrolled_at,
                'points_balance' => $membership->points_balance,
                'lifetime_points_earned' => $membership->lifetime_points_earned,
                'lifetime_points_redeemed' => $membership->lifetime_points_redeemed,
                'last_points_activity_at' => $membership->last_points_activity_at,
                'level_locked' => $membership->level_locked,
            ] : null,
            'orders_count' => $this->whenCounted('orders'),
            'total_spent' => $this->when(isset($this->total_spent), fn () => round((float) $this->total_spent, 2)),
            'last_purchase_at' => $this->when(array_key_exists('last_purchase_at', $this->getAttributes()), fn () => $this->last_purchase_at),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
