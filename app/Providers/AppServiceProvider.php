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
        // Point the emailed password-reset link at the React app instead of a
        // Laravel page. Without this the default notification calls
        // route('password.reset'), which does not exist in this API-only app,
        // so sending the mail would blow up with a RouteNotFoundException.
        //
        // The frontend reads both query params straight back out of the URL and
        // posts them to /api/reset-password - see shared/pages/ResetPassword.jsx.
        ResetPassword::createUrlUsing(function ($user, string $token): string {
            $base = rtrim((string) config('services.sslcommerz.frontend_url'), '/');

            return $base.'/reset-password?token='.$token
                .'&email='.urlencode($user->getEmailForPasswordReset());
        });
    }
}
