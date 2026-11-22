<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Enforced by the `permission` route middleware.
    }

    public function rules(): array
    {
        return [
            'customer_id' => 'nullable|integer|exists:customers,id',
            'contact_name' => 'nullable|required_without:customer_id|string|max:100',
            'contact_info' => 'nullable|string|max:100',
            'type' => ['required', Rule::in(config('shoplink.feedback_types'))],
            'category' => ['nullable', Rule::in(config('shoplink.feedback_categories'))],
            'channel' => ['required', Rule::in(config('shoplink.feedback_channels'))],
            'rating' => 'nullable|integer|min:1|max:5',
            'subject' => 'required|string|max:150',
            'message' => 'required|string|max:5000',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'order_id' => 'nullable|integer|exists:orders,id',
            'product_id' => 'nullable|integer|exists:products,id',
            'priority' => ['nullable', Rule::in(config('shoplink.feedback_priorities'))],
            'received_at' => 'nullable|date|before_or_equal:now',
        ];
    }

    public function messages(): array
    {
        return [
            'contact_name.required_without' => 'Select a customer or enter the contact name of the person giving feedback.',
        ];
    }
}
