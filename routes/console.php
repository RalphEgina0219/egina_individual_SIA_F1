<?php

use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Services\CustomerNotifier;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('customers:birthday-greetings', function (CustomerNotifier $notifier) {
    $today = now();
    $sent = 0;

    Customer::whereIn('status', config('shoplink.transacting_statuses'))
        ->whereNotNull('birthday')
        ->get()
        ->filter(fn ($c) => $c->birthday->month === $today->month && $c->birthday->day === $today->day)
        // Only one greeting per customer per year.
        ->reject(fn ($c) => CustomerNotification::where('customer_id', $c->id)
            ->where('type', 'birthday_promotion')
            ->whereYear('created_at', $today->year)
            ->exists())
        ->each(function ($c) use ($notifier, &$sent) {
            $notifier->send($c, 'birthday_promotion', 'Happy Birthday from ShopLink!', 'Happy birthday! Visit any ShopLink Supermarket branch and ask our Customer Service desk about this month\'s birthday treats for members.');
            $sent++;
        });

    $this->info("Birthday greetings processed: {$sent}");
})->purpose('Send birthday promotion notifications to customers whose birthday is today');

Artisan::command('loyalty:expire-points', function (LoyaltyService $loyalty) {
    $months = $loyalty->settings()['points_expiry_months'];
    if (!$months) {
        $this->info('Points expiry is not configured; nothing to do.');

        return;
    }

    $this->info('Expired points on ' . $loyalty->expireInactiveBalances() . ' account(s).');
})->purpose('Expire loyalty points of members inactive longer than the configured period');

Schedule::command('customers:birthday-greetings')->dailyAt('08:00');
Schedule::command('loyalty:expire-points')->dailyAt('01:00');
