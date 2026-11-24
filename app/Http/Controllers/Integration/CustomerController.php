<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\IntegrationResponse;
use Illuminate\Http\JsonResponse;

class CustomerController extends Controller
{
    /**
     * GET /customers/{customer_code}
     * Pangunahing impormasyon lang. Walang email, phone, address o birthday (privacy).
     */
    public function show(string $customerCode): JsonResponse
    {
        $customer = Customer::with('membership.level')->where('customer_code', $customerCode)->first();

        if (!$customer) {
            return IntegrationResponse::error('Customer not found', ['customer_code' => ["No customer with code {$customerCode}."]], 404);
        }

        return IntegrationResponse::ok([
            'customer_code' => $customer->customer_code,
            'full_name' => $customer->full_name,
            'membership_level' => $customer->membership?->level?->name,
            'loyalty_points' => (int) ($customer->membership?->points_balance ?? 0),
            'status' => $customer->status,
            'updated_at' => IntegrationResponse::date($customer->updated_at),
        ]);
    }
}
