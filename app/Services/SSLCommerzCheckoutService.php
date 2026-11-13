<?php

namespace App\Services;

use App\Models\TaxAssessment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * SSL Commerz hosted-checkout integration.
 *
 * SSL Commerz exposes a small JSON API:
 *   - https://sandbox.sslcommerz.com/gwprocess/v4/api.php  (sandbox)
 *   - https://securepay.sslcommerz.com/gwprocess/v4/api.php (live)
 *
 * We POST a Session API request with the cart details. The response
 * contains a `GatewayPageURL` (or `redirectGatewayURL`) we redirect the
 * customer to. On return, SSLCZ hits our success/fail/cancel/IPN URLs.
 *
 * We deliberately don't pull in the official `sslcommerz-php` package
 * because (a) composer install may be unavailable in some environments
 * and (b) the entire surface is two POST endpoints — a tiny native
 * client keeps the failure modes obvious.
 */
class SSLCommerzCheckoutService
{
    public function __construct(
        private readonly string $storeId,
        private readonly string $storePassword,
        private readonly string $currency,
        private readonly string $successUrl,
        private readonly string $failUrl,
        private readonly string $cancelUrl,
        private readonly string $ipnUrl,
        private readonly bool $sandbox = true,
        private readonly int $connectTimeout = 30,
        private readonly bool $demoMode = false,
    ) {}

    /**
     * Start an SSL Commerz session for an assessment. Returns the URL
     * the customer's browser should be redirected to. Throws on any
     * transport / API failure so the controller can surface a clear
     * error message.
     */
    public function createPaymentSession(TaxAssessment $assessment, User $user): string
    {
        /*
         * Demo mode: skip SSLCZ entirely. Generate a fake session and
         * return a URL pointing at our own success controller. The
         * caller will see a realistic payment flow without any money
         * changing hands.
         */
        if ($this->demoMode) {
            return $this->buildDemoSuccessUrl($assessment, $user);
        }

        if ($this->storeId === '' || $this->storePassword === '') {
            throw new RuntimeException(
                'SSL Commerz is not configured (SSLCZ_STORE_ID / SSLCZ_STORE_PASSWORD missing).'
            );
        }

        $amount = (float) $assessment->tax_amount;
        if ($amount <= 0) {
            throw new RuntimeException('Assessment amount must be greater than zero.');
        }

        /*
         * tran_id MUST be unique per request — SSLCZ will reject a
         * duplicate. Combine the assessment id + a short random suffix
         * so simultaneous attempts on the same assessment don't clash.
         */
        $transactionId = sprintf(
            'TAX-%s-%s-%s',
            $assessment->id,
            $user->id,
            strtoupper(bin2hex(random_bytes(4)))
        );

        $payload = [
            'store_id' => $this->storeId,
            'store_passwd' => $this->storePassword,
            'total_amount' => number_format($amount, 2, '.', ''),
            'currency' => strtoupper($this->currency ?: 'BDT'),
            'tran_id' => $transactionId,
            'success_url' => $this->successUrl,
            'fail_url' => $this->failUrl,
            'cancel_url' => $this->cancelUrl,
            'ipn_url' => $this->ipnUrl,

            // Required customer + product metadata. SSLCZ rejects the
            // request if any of these are missing.
            'product_name' => sprintf('Property Tax %s', $assessment->fiscal_year),
            'product_category' => 'Tax',
            'product_profile' => 'general',
            'product_amount' => number_format($amount, 2, '.', ''),
            'product_qty' => 1,

            'cus_name' => $user->full_name ?: $user->name ?: 'Citizen',
            'cus_email' => $user->email,
            'cus_add1' => $user->present_address ?: ($user->permanent_address ?: 'N/A'),
            'cus_add2' => $user->permanent_address ?: 'N/A',
            'cus_city' => 'Dhaka',
            'cus_state' => 'Dhaka',
            'cus_postcode' => '1000',
            'cus_country' => 'Bangladesh',
            'cus_phone' => $user->phone_number ?: 'N/A',
            'cus_fax' => '',

            'ship_name' => $user->full_name ?: $user->name ?: 'Citizen',
            'ship_add1' => $user->present_address ?: 'N/A',
            'ship_add2' => $user->permanent_address ?: 'N/A',
            'ship_city' => 'Dhaka',
            'ship_state' => 'Dhaka',
            'ship_postcode' => '1000',
            'ship_country' => 'Bangladesh',

            'shipping_method' => 'NO',
            'num_of_item' => 1,

            // Pass our own references through the round trip so the IPN
            // and success URLs can locate the assessment without
            // trusting unverified customer input.
            'value_a' => 'assessment_id:'.$assessment->id,
            'value_b' => 'user_id:'.$user->id,
            'value_c' => 'tax_amount:'.number_format($amount, 2, '.', ''),
            'value_d' => 'fiscal_year:'.$assessment->fiscal_year,

            'emi_option' => 0,
            'multi_card_name' => '',
            'allowed_bin' => '',
        ];

        $response = Http::asForm()
            ->timeout($this->connectTimeout)
            ->post($this->apiUrl(), $payload);

        if (! $response->successful()) {
            Log::error('SSL Commerz session request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException(
                'SSL Commerz responded with HTTP '.$response->status().'.'
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('SSL Commerz returned a non-JSON response.');
        }

        /*
         * SSLCZ returns either:
         *   { "status": "FAILED", "failedreason": "..." }   -> error
         *   { "status": "SUCCESS",
         *     "GatewayPageURL": "https://...",
         *     "redirectGatewayURL": "https://...",
         *     ... }                                         -> ok
         */
        $status = strtoupper((string) ($body['status'] ?? ''));

        if ($status !== 'SUCCESS') {
            $reason = (string) ($body['failedreason'] ?? 'Unknown SSL Commerz error.');
            Log::warning('SSL Commerz refused to start a session', [
                'response' => $body,
            ]);
            throw new RuntimeException('SSL Commerz error: '.$reason);
        }

        $url = $body['GatewayPageURL'] ?? $body['redirectGatewayURL'] ?? null;
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('SSL Commerz response was SUCCESS but no GatewayPageURL was provided.');
        }

        return $url;
    }

    /**
     * Validate a transaction-id + amount + currency combo against
     * SSLCZ's order validation API. SSLCZ recommends this be done on
     * the success callback AND on the IPN.
     */
    public function validateTransaction(string $validationId): array
    {
        if ($validationId === '') {
            throw new RuntimeException('Missing validation ID.');
        }

        $payload = [
            'store_id' => $this->storeId,
            'store_passwd' => $this->storePassword,
            'val_id' => $validationId,
            'v' => 1,
            'format' => 'json',
        ];

        $response = Http::asForm()
            ->timeout($this->connectTimeout)
            ->post($this->validationUrl(), $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                'SSL Commerz validation API responded with HTTP '.$response->status().'.'
            );
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('SSL Commerz validation returned non-JSON.');
        }

        return $body;
    }

    /**
     * Validate a transaction against the gateway and confirm it matches
     * the assessment we are about to mark as paid.
     *
     * This is the security-critical step: the amount, currency and
     * transaction id arriving on the success/IPN callback are supplied
     * by the browser (or by whoever crafted the request), so they must
     * never be trusted on their own. We re-query the gateway's
     * Validation API and compare what it says against our own books.
     *
     * @return array{valid: bool, reason: string|null, data: array}
     */
    public function validateTransactionForAssessment(
        string $validationId,
        TaxAssessment $assessment,
        string $expectedTranId = ''
    ): array {
        if ($this->demoMode) {
            // Demo mode never talks to the gateway; trust the local flow.
            return ['valid' => true, 'reason' => null, 'data' => ['demo' => true]];
        }

        if (trim($validationId) === '') {
            return ['valid' => false, 'reason' => 'missing validation id', 'data' => []];
        }

        try {
            $data = $this->validateTransaction($validationId);
        } catch (\Throwable $exception) {
            report($exception);

            return ['valid' => false, 'reason' => 'gateway validation failed: '.$exception->getMessage(), 'data' => []];
        }

        $gatewayStatus = strtoupper((string) ($data['status'] ?? ''));
        if (! in_array($gatewayStatus, ['VALID', 'VALIDATED'], true)) {
            return ['valid' => false, 'reason' => 'gateway status is '.($gatewayStatus ?: 'unknown'), 'data' => $data];
        }

        // Currency must match the currency we opened the session with.
        $gatewayCurrency = strtoupper((string) ($data['currency'] ?? ''));
        $expectedCurrency = strtoupper($this->currency);
        if ($gatewayCurrency !== '' && $gatewayCurrency !== $expectedCurrency) {
            return [
                'valid' => false,
                'reason' => "currency mismatch: expected {$expectedCurrency}, got {$gatewayCurrency}",
                'data' => $data,
            ];
        }

        // The amount the gateway captured must equal what we asked for.
        $gatewayAmount = (float) ($data['amount'] ?? 0);
        $expectedAmount = (float) $assessment->tax_amount;
        if (abs($gatewayAmount - $expectedAmount) > 0.009) {
            return [
                'valid' => false,
                'reason' => sprintf('amount mismatch: expected %.2f, gateway reported %.2f', $expectedAmount, $gatewayAmount),
                'data' => $data,
            ];
        }

        // The transaction id must be the one we generated.
        $gatewayTranId = (string) ($data['tran_id'] ?? '');
        if ($expectedTranId !== '' && $gatewayTranId !== '' && $gatewayTranId !== $expectedTranId) {
            return ['valid' => false, 'reason' => 'transaction id mismatch', 'data' => $data];
        }

        return ['valid' => true, 'reason' => null, 'data' => $data];
    }

    public function isConfigured(): bool
    {
        // Demo mode is always considered "configured" — it works
        // without external credentials.
        return $this->demoMode || ($this->storeId !== '' && $this->storePassword !== '');
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function isDemoMode(): bool
    {
        return $this->demoMode;
    }

    /**
     * Build the demo success URL. Mirrors the parameters SSLCZ would
     * normally POST to our success callback, so the downstream
     * controller treats a demo the same as a real successful payment.
     */
    public function buildDemoSuccessUrl(TaxAssessment $assessment, User $user): string
    {
        $transactionId = sprintf(
            'DEMO-TAX-%s-%s-%s',
            $assessment->id,
            $user->id,
            strtoupper(bin2hex(random_bytes(4)))
        );
        $validationId = 'DEMO-'.strtoupper(bin2hex(random_bytes(6)));
        $amount = number_format((float) $assessment->tax_amount, 2, '.', '');

        /*
         * We bypass the SSLCZ success endpoint entirely — the demo
         * success URL hits our own demo-confirm route so the citizen
         * never sees a fake "sandbox.sslcommerz.com" page they can't
         * reach.
         */
        return route('citizen.taxes.payment.demo', [
            'tran_id' => $transactionId,
            'val_id' => $validationId,
            'amount' => $amount,
        ], absolute: true);
    }

    /**
     * Build a demo tran_id for an assessment. Used when we need to
     * fabricate a `value_a`-compatible string for IPN confirmation in
     * demo mode.
     */
    public function demoValueA(TaxAssessment $assessment): string
    {
        return 'assessment_id:'.$assessment->id;
    }

    private function apiUrl(): string
    {
        return $this->sandbox
            ? 'https://sandbox.sslcommerz.com/gwprocess/v4/api.php'
            : 'https://securepay.sslcommerz.com/gwprocess/v4/api.php';
    }

    private function validationUrl(): string
    {
        return $this->sandbox
            ? 'https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php'
            : 'https://securepay.sslcommerz.com/validator/api/validationserverAPI.php';
    }
}
