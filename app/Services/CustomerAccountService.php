<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer registration (Module 1) and account maintenance (Module 6).
 */
class CustomerAccountService
{
    // Fields staff may change through an account update request.
    public const EDITABLE_FIELDS = [
        'full_name', 'phone', 'email', 'address', 'birthday', 'preferred_branch_id',
        'preferred_contact_method', 'customer_type', 'notes',
    ];

    public function __construct(
        private LoyaltyService $loyalty,
        private CustomerNotifier $notifier,
    ) {
    }

    public static function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        // 09171234567, +639171234567 and 639171234567 all compare as 9171234567.
        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    /**
     * Possible duplicate accounts. "blocking" matches (same phone or e-mail)
     * always prevent creation; "possible" matches (same name, or same name and
     * birthday) require staff confirmation.
     *
     * @return Collection<int, array{customer: Customer, reasons: string[], blocking: bool}>
     */
    public function findDuplicates(array $data, ?int $exceptId = null): Collection
    {
        $matches = collect();
        $add = function (Customer $customer, string $reason, bool $blocking) use ($matches) {
            $entry = $matches->get($customer->id, ['customer' => $customer, 'reasons' => [], 'blocking' => false]);
            $entry['reasons'][] = $reason;
            $entry['blocking'] = $entry['blocking'] || $blocking;
            $matches->put($customer->id, $entry);
        };
        $base = fn () => Customer::query()->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId));

        $phone = self::normalizePhone($data['phone'] ?? null);
        if (strlen($phone) >= 7) {
            $base()->where('phone', 'like', '%' . substr($phone, -4) . '%')->get()
                ->filter(fn ($c) => self::normalizePhone($c->phone) === $phone)
                ->each(fn ($c) => $add($c, 'Same contact number', true));
        }

        if (!empty($data['email'])) {
            $base()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->get()
                ->each(fn ($c) => $add($c, 'Same e-mail address', true));
        }

        if (!empty($data['full_name'])) {
            $name = mb_strtolower(preg_replace('/\s+/', ' ', trim($data['full_name'])));
            $base()->whereRaw('LOWER(full_name) = ?', [$name])->get()
                ->each(function ($c) use ($add, $data) {
                    $sameBirthday = !empty($data['birthday']) && $c->birthday?->format('Y-m-d') === date('Y-m-d', strtotime($data['birthday']));
                    $add($c, $sameBirthday ? 'Same name and birthday' : 'Same name', false);
                });
        }

        return $matches->values();
    }

    public static function presentDuplicates(Collection $duplicates): array
    {
        return $duplicates->map(fn ($d) => [
            'id' => $d['customer']->id,
            'customer_code' => $d['customer']->customer_code,
            'full_name' => $d['customer']->full_name,
            'phone' => $d['customer']->phone,
            'email' => $d['customer']->email,
            'status' => $d['customer']->status,
            'reasons' => $d['reasons'],
            'blocking' => $d['blocking'],
        ])->all();
    }

    private function guardDuplicates(array $data, ?int $exceptId, bool $confirmed): void
    {
        $duplicates = $this->findDuplicates($data, $exceptId);
        if ($duplicates->isEmpty()) {
            return;
        }

        if ($duplicates->contains('blocking', true)) {
            throw new BusinessRuleException('A customer account with the same contact number or e-mail already exists.', 409, [
                'duplicates' => self::presentDuplicates($duplicates),
                'requires_confirmation' => false,
            ]);
        }

        if (!$confirmed) {
            throw new BusinessRuleException('Possible duplicate customer found. Review the matches and confirm to continue.', 409, [
                'duplicates' => self::presentDuplicates($duplicates),
                'requires_confirmation' => true,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Module 1: Registration
    // ------------------------------------------------------------------

    /**
     * @param  array  $options  confirm_not_duplicate, identity_verified, verification_method, activate, enroll_loyalty
     */
    public function register(array $data, User $staff, array $options = []): Customer
    {
        $this->guardDuplicates($data, null, (bool) ($options['confirm_not_duplicate'] ?? false));

        if (!empty($options['activate']) && empty($options['identity_verified'])) {
            throw new BusinessRuleException('Verify the customer identity before activating the account.');
        }

        $customer = DB::transaction(function () use ($data, $staff, $options) {
            $customer = Customer::create(array_merge($data, [
                'status' => 'Pending Verification',
                'registered_by' => $staff->id,
            ]));

            $this->loyalty->membershipFor($customer);

            CustomerAccountLogger::log($customer, 'registered', "Customer account created ({$customer->customer_code}).", [
                'customer_code' => $customer->customer_code,
                'membership_number' => $customer->membership_number,
            ], ['request_channel' => $customer->registration_channel]);

            return $customer;
        });

        $customer->refresh();

        $this->notifier->send(
            $customer,
            'registration',
            'Your ShopLink customer account has been registered',
            "Your customer account has been created.\nCustomer ID: {$customer->customer_code}\nMembership Number: {$customer->membership_number}\nYour account will be activated after verification."
        );

        if (!empty($options['identity_verified'])) {
            $this->verify($customer, $options['verification_method'] ?? 'Government-issued ID', null, $staff);
        }
        if (!empty($options['activate'])) {
            $this->activate($customer, $staff);
        }
        if (!empty($options['enroll_loyalty'])) {
            $this->loyalty->enroll($customer->refresh(), $staff);
        }

        return $customer->refresh();
    }

    public function verify(Customer $customer, string $method, ?string $notes, User $staff): Customer
    {
        if ($customer->verified_at) {
            throw new BusinessRuleException('Customer identity is already verified.');
        }
        if (in_array($customer->status, ['Blocked', 'Archived'], true)) {
            throw new BusinessRuleException("Cannot verify a {$customer->status} account.");
        }

        $old = $customer->status;
        $customer->update([
            'verified_at' => now(),
            'verified_by' => $staff->id,
            'verification_method' => $method,
            'status' => $old === 'Pending Verification' ? 'Verified' : $old,
            'status_changed_at' => $old === 'Pending Verification' ? now() : $customer->status_changed_at,
        ]);

        CustomerAccountLogger::log($customer, 'verified', "Identity verified via {$method}.", [
            'status' => ['old' => $old, 'new' => $customer->status],
            'notes' => $notes,
        ], ['identity_verified' => true, 'verification_method' => $method]);

        $this->loyalty->ensureRegisteredLevel($customer);

        $this->notifier->send($customer, 'verification', 'Your ShopLink account has been verified', 'Your customer account identity has been verified.');

        return $customer->refresh();
    }

    public function activate(Customer $customer, User $staff): Customer
    {
        if (!$customer->verified_at) {
            throw new BusinessRuleException('Customer identity must be verified before activation.');
        }
        if (in_array($customer->status, ['Active', 'VIP'], true)) {
            throw new BusinessRuleException('Customer account is already active.');
        }
        if (in_array($customer->status, ['Blocked', 'Archived'], true)) {
            throw new BusinessRuleException("A {$customer->status} account must be reviewed through a status change, not activation.");
        }

        $old = $customer->status;
        $firstActivation = $customer->activated_at === null;

        $customer->update([
            'status' => 'Active',
            'activated_at' => $customer->activated_at ?? now(),
            'status_changed_at' => now(),
            'status_reason' => null,
        ]);

        $this->loyalty->ensureRegisteredLevel($customer);

        CustomerAccountLogger::log($customer, 'activated', 'Customer account activated.', [
            'status' => ['old' => $old, 'new' => 'Active'],
        ]);

        if ($firstActivation) {
            $this->notifier->send($customer, 'welcome', 'Welcome to ShopLink Supermarket', "Your ShopLink customer account is now active.\nCustomer ID: {$customer->customer_code}\nYou are now eligible to join the ShopLink loyalty program.");
        } else {
            $this->notifier->send($customer, 'account_status', 'Your ShopLink account is active', 'Your customer account has been re-activated.');
        }

        return $customer->refresh();
    }

    public function changeStatus(Customer $customer, string $status, ?string $reason, User $staff): Customer
    {
        $old = $customer->status;

        if ($old === $status) {
            throw new BusinessRuleException("Customer is already {$status}.");
        }
        if (in_array($status, ['Inactive', 'Suspended', 'Blocked', 'Archived'], true) && !$reason) {
            throw new BusinessRuleException("A reason is required to set the status to {$status}.");
        }
        if (in_array($status, ['Verified', 'Active', 'VIP'], true) && !$customer->verified_at) {
            throw new BusinessRuleException('Customer identity must be verified first.');
        }
        if ($status === 'Pending Verification' && $customer->verified_at) {
            throw new BusinessRuleException('A verified account cannot return to Pending Verification.');
        }

        $customer->update([
            'status' => $status,
            'status_reason' => $reason,
            'status_changed_at' => now(),
            'activated_at' => in_array($status, ['Active', 'VIP'], true) ? ($customer->activated_at ?? now()) : $customer->activated_at,
        ]);

        if (in_array($status, ['Active', 'VIP', 'Verified'], true)) {
            $this->loyalty->ensureRegisteredLevel($customer);
        }

        CustomerAccountLogger::log($customer, 'status_changed', "Status changed from {$old} to {$status}.", [
            'status' => ['old' => $old, 'new' => $status],
            'reason' => $reason,
        ]);

        if ($status !== 'Archived') {
            $this->notifier->send($customer, 'account_status', 'Your ShopLink account status has changed', "Your customer account status is now: {$status}." . ($reason ? "\nReason: {$reason}" : ''));
        }

        return $customer->refresh();
    }

    // ------------------------------------------------------------------
    // Module 6: Account update and maintenance
    // ------------------------------------------------------------------

    /**
     * @param  array  $context  identity_verified, verification_method, request_channel, confirm_not_duplicate, notify_customer
     */
    public function update(Customer $customer, array $data, User $staff, array $context = []): Customer
    {
        $data = array_intersect_key($data, array_flip(self::EDITABLE_FIELDS));

        $changes = [];
        foreach ($data as $field => $value) {
            $current = $customer->{$field};
            $current = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : $current;
            $new = $field === 'birthday' && $value ? date('Y-m-d', strtotime($value)) : $value;
            if ((string) $current !== (string) $new) {
                $changes[$field] = ['old' => $current, 'new' => $new];
            }
        }

        if (!$changes) {
            throw new BusinessRuleException('No changes were submitted.');
        }

        // Contact details are identity data: changing them requires identity verification.
        $sensitive = array_intersect(array_keys($changes), ['full_name', 'phone', 'email', 'birthday']);
        if ($sensitive && empty($context['identity_verified'])) {
            throw new BusinessRuleException('Confirm the customer identity before changing name, contact number, e-mail or birthday.');
        }

        if ($sensitive) {
            $this->guardDuplicates(array_merge($customer->only(['full_name', 'phone', 'email']), ['birthday' => $customer->birthday?->format('Y-m-d')], $data), $customer->id, (bool) ($context['confirm_not_duplicate'] ?? false));
        }

        DB::transaction(function () use ($customer, $data, $changes, $context) {
            $customer->update($data);

            CustomerAccountLogger::log($customer, 'updated', 'Updated ' . implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), array_keys($changes))) . '.', $changes, [
                'identity_verified' => $context['identity_verified'] ?? false,
                'verification_method' => $context['verification_method'] ?? null,
                'request_channel' => $context['request_channel'] ?? null,
            ]);
        });

        if (!empty($context['notify_customer'])) {
            $this->notifier->send($customer, 'account_update', 'Your ShopLink account details were updated', 'The following details on your customer account were updated: ' . implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), array_keys($changes))) . '.');
        }

        return $customer->refresh();
    }
}
