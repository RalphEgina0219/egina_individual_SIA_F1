<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Notifications\CustomerMessage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends customer notifications by e-mail (the only channel with a configured
 * transport) and records every attempt in customer_notifications, including
 * skipped ones, so staff can see what the customer was told.
 *
 * Delivery uses the application's mail configuration (MAIL_* in .env). With
 * the default MAIL_MAILER=log, messages are written to storage/logs instead
 * of being delivered. SMS is not implemented: SMS-preferring customers with an
 * e-mail address receive e-mail, others are recorded as skipped.
 */
class CustomerNotifier
{
    public const TYPES = [
        'registration' => 'Account Registration',
        'verification' => 'Account Verification',
        'welcome' => 'Welcome Message',
        'points_earned' => 'Loyalty Points Earned',
        'points_redeemed' => 'Loyalty Points Redeemed',
        'membership_upgrade' => 'Membership Upgrade',
        'birthday_promotion' => 'Birthday Promotion',
        'feedback_received' => 'Customer Feedback Received',
        'complaint_status' => 'Complaint Status Update',
        'promotional' => 'Promotional Announcement',
        'account_status' => 'Account Status Notification',
        'account_update' => 'Account Update Confirmation',
    ];

    // Marketing messages respect the "None" contact preference.
    private const PROMOTIONAL_TYPES = ['birthday_promotion', 'promotional'];

    public function send(Customer $customer, string $type, string $subject, string $body): CustomerNotification
    {
        $record = [
            'customer_id' => $customer->id,
            'type' => $type,
            'channel' => 'Email',
            'recipient' => $customer->email,
            'subject' => $subject,
            'body' => $body,
            'sent_by' => Auth::id(),
        ];

        if (!config('shoplink.notifications.enabled')) {
            return $this->record($record, 'Skipped', 'Customer notifications are disabled (SHOPLINK_NOTIFICATIONS_ENABLED=false).');
        }

        if (in_array($type, self::PROMOTIONAL_TYPES, true) && $customer->preferred_contact_method === 'None') {
            return $this->record($record, 'Skipped', 'Customer opted out of promotional contact.');
        }

        if (!$customer->email) {
            return $this->record(
                array_merge($record, ['channel' => $customer->preferred_contact_method]),
                'Skipped',
                'No e-mail address on file and no SMS gateway is configured.'
            );
        }

        try {
            Notification::route('mail', $customer->email)
                ->notify(new CustomerMessage($customer->full_name, $subject, $body));

            return $this->record($record, 'Sent');
        } catch (Throwable $e) {
            Log::warning("Customer notification {$type} for customer {$customer->id} failed: " . $e->getMessage());

            return $this->record($record, 'Failed', Str::limit($e->getMessage(), 250));
        }
    }

    private function record(array $attributes, string $status, ?string $error = null): CustomerNotification
    {
        return CustomerNotification::create(array_merge($attributes, [
            'status' => $status,
            'error' => $error,
        ]));
    }
}
