<?php

namespace App\Providers;

use App\Services\SSLCommerzCheckoutService;
use App\Services\StripeCheckoutService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Stripe bindings (kept for backwards compatibility — no longer
         * used by the citizen tax payment flow, but the service is still
         * injected into the deprecated StripeTaxPaymentController).
         */
        $this->app->singleton(StripeClient::class, function () {
            $secret = config('services.stripe.secret');

            if (empty($secret)) {
                $secret = 'sk_test_placeholder_not_real';
            }

            return new StripeClient($secret);
        });

        $this->app->singleton(StripeCheckoutService::class, function ($app) {
            return new StripeCheckoutService(
                $app->make(StripeClient::class),
                config('services.stripe.currency')
            );
        });

        /*
         * SSL Commerz (active payment gateway for BDT).
         */
        $this->app->singleton(SSLCommerzCheckoutService::class, function () {
            $cfg = config('services.sslcommerz', []);

            $storeId = (string) ($cfg['store_id'] ?? '');
            $storePassword = (string) ($cfg['store_passwd'] ?? '');

            /*
             * Auto-enable demo mode when the credentials look like the
             * placeholder values shipped with the repo, OR when the operator
             * has not configured any credentials at all. This keeps the
             * payment button enabled out-of-the-box without leaking real
             * sandbox credentials into the repository.
             */
            $looksLikePlaceholder = $storeId !== '' && (
                str_contains($storeId, 'placeholder')
                || str_contains(strtolower($storeId), 'demo')
                || str_contains($storePassword, 'placeholder')
            );

            $autoDemo = $storeId === ''
                || $storePassword === ''
                || $looksLikePlaceholder;

            $demoMode = (bool) ($cfg['demo_mode'] ?? false) || $autoDemo;

            return new SSLCommerzCheckoutService(
                storeId: $storeId,
                storePassword: $storePassword,
                currency: (string) ($cfg['currency'] ?? 'BDT'),
                successUrl: (string) ($cfg['success_url'] ?? ''),
                failUrl: (string) ($cfg['fail_url'] ?? ''),
                cancelUrl: (string) ($cfg['cancel_url'] ?? ''),
                ipnUrl: (string) ($cfg['ipn_url'] ?? ''),
                sandbox: (bool) ($cfg['sandbox'] ?? true),
                connectTimeout: (int) ($cfg['connect_timeout'] ?? 30),
                demoMode: $demoMode,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        $this->registerRateLimiters();
    }

    /**
     * Register named rate limiters.
     *
     * The login endpoint is the primary credential surface, so it gets
     * its own limiter keyed on the email + IP pair. Without this an
     * attacker could brute-force an account with unlimited attempts.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('login:'.$email.'|'.$request->ip()),
                Limit::perHour(25)->by('login-ip:'.$request->ip()),
            ];
        });

        // Self-service registration can be abused to mass-create accounts.
        RateLimiter::for('register', function (Request $request) {
            return [
                Limit::perHour(10)->by('register:'.$request->ip()),
            ];
        });

        // The NID registry probe is a public endpoint; keep it from
        // being used to enumerate valid NIDs.
        RateLimiter::for('nid-status', function (Request $request) {
            return Limit::perMinute(20)->by('nid:'.$request->ip());
        });
    }
}
