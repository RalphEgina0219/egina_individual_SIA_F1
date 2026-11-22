<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAccountLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class CustomerAccountLogger
{
    /**
     * @param  array{identity_verified?: bool, verification_method?: ?string, request_channel?: ?string}  $context
     */
    public static function log(
        Customer $customer,
        string $action,
        string $description,
        ?array $changes = null,
        array $context = []
    ): CustomerAccountLog {
        return CustomerAccountLog::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id(),
            'action' => $action,
            'changes' => $changes,
            'description' => $description,
            'identity_verified' => (bool) ($context['identity_verified'] ?? false),
            'verification_method' => $context['verification_method'] ?? null,
            'request_channel' => $context['request_channel'] ?? null,
            'ip_address' => Request::ip(),
        ]);
    }
}
