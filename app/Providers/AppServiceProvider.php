<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Password reset e-mails link to the React app instead of a Laravel route.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            return rtrim(config('shoplink.frontend_url'), '/') . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
        });
    }
}
