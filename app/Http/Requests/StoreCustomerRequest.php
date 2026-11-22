<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Enforced by the `permission` route middleware.
    }

    /**
     * Uniqueness of phone / e-mail is checked by the duplicate detection in
     * CustomerAccountService so staff see the matching accounts (HTTP 409).
     */
    public function rules(): array
    {
        return [
            'full_name' => 'required|string|min:2|max:100',
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            'email' => 'nullable|email|max:100',
            'address' => 'nullable|string|max:200',
            'birthday' => 'nullable|date|before:today|after:1900-01-01',
            'preferred_branch_id' => 'nullable|integer|exists:branches,id',
            'preferred_contact_method' => ['required', Rule::in(config('shoplink.contact_methods'))],
            'customer_type' => ['required', Rule::in(config('shoplink.customer_types'))],
            'registration_channel' => ['required', Rule::in(config('shoplink.registration_channels'))],
            'notes' => 'nullable|string|max:1000',

            'confirm_not_duplicate' => 'sometimes|boolean',
            'identity_verified' => 'sometimes|boolean',
            'verification_method' => ['nullable', 'required_if:identity_verified,true', Rule::in(config('shoplink.verification_methods'))],
            'activate' => 'sometimes|boolean',
            'enroll_loyalty' => 'sometimes|boolean',
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
        return $this->safe()->only([
            'full_name', 'phone', 'email', 'address', 'birthday', 'preferred_branch_id',
            'preferred_contact_method', 'customer_type', 'registration_channel', 'notes',
        ]);
    }
}
