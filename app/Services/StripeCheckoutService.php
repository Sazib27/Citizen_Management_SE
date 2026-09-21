<?php

namespace App\Services;

use App\Models\TaxAssessment;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

class StripeCheckoutService
{
    public function __construct(
        private readonly StripeClient $client,
        private readonly ?string $currency = null,
    ) {
    }

    public function createPaymentSession(TaxAssessment $assessment, User $user): Session
    {
        $successUrl = $this->resolveSuccessUrl();
        $cancelUrl = $this->resolveCancelUrl();

        return $this->client->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $user->email,
            'metadata' => [
                'assessment_id' => (string) $assessment->id,
                'user_id' => (string) $user->id,
            ],
            'line_items' => [[
                'price_data' => [
                    'currency' => $this->resolveStripeCurrency(),
                    'unit_amount' => $this->toStripeAmount($assessment->tax_amount),
                    'product_data' => [
                        'name' => sprintf('Property Tax %s', $assessment->fiscal_year),
                        'description' => $assessment->property?->title,
                    ],
                ],
                'quantity' => 1,
            ]],
        ]);
    }

    public function retrieveSession(string $sessionId): Session
    {
        return $this->client->checkout->sessions->retrieve($sessionId, [
            'expand' => ['payment_intent'],
        ]);
    }

    private function resolveSuccessUrl(): string
    {
        if ($configured = config('services.stripe.success_url')) {
            return $configured;
        }

        $relativePath = route('citizen.taxes.payment.success', [], false);

        return URL::to($relativePath) . '?session_id={CHECKOUT_SESSION_ID}';
    }

    private function resolveCancelUrl(): string
    {
        if ($configured = config('services.stripe.cancel_url')) {
            return $configured;
        }

        return URL::to(route('citizen.taxes.index', [], false));
    }

    private function toStripeAmount(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Stripe only supports specific ISO currency codes. If the
     * configured currency is unsupported (e.g. bdt), fall back to usd
     * so the API call does not throw. Logged so the operator sees the
     * mismatch.
     */
    private function resolveStripeCurrency(): string
    {
        $supported = [
            'usd', 'eur', 'gbp', 'jpy', 'aud', 'cad', 'chf', 'sek', 'nok',
            'dkk', 'sgd', 'hkd', 'nzd', 'mxn', 'brl', 'inr', 'try',
            'zar', 'pln', 'czk', 'ils', 'php', 'thb', 'myr', 'idr',
        ];

        $candidate = strtolower(trim((string) ($this->currency ?: '')));

        if ($candidate === '' || !in_array($candidate, $supported, true)) {
            if ($candidate !== '' && $candidate !== 'usd') {
                \Log::warning('Stripe does not support configured currency; falling back to USD.', [
                    'configured' => $candidate,
                ]);
            }
            return 'usd';
        }

        return $candidate;
    }
}
