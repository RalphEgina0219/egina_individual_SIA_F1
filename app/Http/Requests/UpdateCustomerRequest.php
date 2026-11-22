<?php

namespace App\Http\Requests;

use App\Services\CustomerAccountService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Enforced by the `permission` route middleware.
    }

    /**
     * Customer ID, membership number and status are intentionally not
     * editable here; status has its own audited endpoint.
     */
    public function rules(): array
    {
        return [
            'full_name' => 'sometimes|required|string|min:2|max:100',
            'phone' => ['sometimes', 'required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            'email' => 'sometimes|nullable|email|max:100',
            'address' => 'sometimes|nullable|string|max:200',
            'birthday' => 'sometimes|nullable|date|before:today|after:1900-01-01',
            'preferred_branch_id' => 'sometimes|nullable|integer|exists:branches,id',
            'preferred_contact_method' => ['sometimes', 'required', Rule::in(config('shoplink.contact_methods'))],
            'customer_type' => ['sometimes', 'required', Rule::in(config('shoplink.customer_types'))],
            'notes' => 'sometimes|nullable|string|max:1000',

            'identity_verified' => 'sometimes|boolean',
            'verification_method' => ['nullable', 'required_if:identity_verified,true', Rule::in(config('shoplink.verification_methods'))],
            'request_channel' => ['nullable', Rule::in(config('shoplink.registration_channels'))],
            'confirm_not_duplicate' => 'sometimes|boolean',
            'notify_customer' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid contact number (digits, spaces, +, - only).',
            'verification_method.required_if' => 'Select how the customer identity was verified.',
        ];
    }

    public function customerData(): array
    {
        return $this->safe()->only(CustomerAccountService::EDITABLE_FIELDS);
    }

    public function context(): array
    {
        return $this->safe()->only(['identity_verified', 'verification_method', 'request_channel', 'confirm_not_duplicate', 'notify_customer']);
    }
}
