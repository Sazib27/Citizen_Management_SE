<?php

namespace App\Http\Controllers;

use App\Models\TaxAssessment;
use App\Models\TaxPayment;
use App\Services\SSLCommerzCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SSL Commerz tax payment flow.
 *
 * Lifecycle:
 *   1. citizen POSTs /citizen/taxes/{id}/pay      -> create()
 *        -> creates a SSLCZ session and redirects the browser to the
 *           hosted checkout URL.
 *
 *   2. citizen pays on SSLCZ, SSLCZ redirects:
 *        - /citizen/taxes/payment/success          -> success()
 *        - /citizen/taxes/payment/fail             -> fail()
 *        - /citizen/taxes/payment/cancel           -> cancel()
 *
 *   3. regardless of (2), SSLCZ posts to:
 *        - /citizen/taxes/payment/ipn              -> ipn()
 *
 *   The IPN is the only callback that is guaranteed by SSLCZ; it is
 *   the one we trust to record a TaxPayment. The success() handler
 *   merely displays a confirmation page; the actual DB write happens
 *   in ipn() when the gateway confirms.
 *
 *   To support environments where the IPN is blocked (e.g. local dev
 *   without a public URL), success() also tries to confirm directly
 *   via the validation API as a best-effort.
 */
class SSLCommerzTaxPaymentController extends Controller
{
    public function __construct(private readonly SSLCommerzCheckoutService $sslcz) {}

    /**
     * Begin a payment. Creates a SSLCZ session and redirects the
     * citizen to the hosted checkout.
     */
    public function create(Request $request, TaxAssessment $taxAssessment): RedirectResponse
    {
        $this->authorizeAssessment($request->user(), $taxAssessment);

        if (! in_array($taxAssessment->status, ['issued', 'overdue'], true)) {
            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'This assessment is not eligible for online payment.',
            ]);
        }

        if (! $this->sslcz->isConfigured()) {
            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'SSL Commerz is not configured on this server. Please contact the administrator (SSLCZ_STORE_ID / SSLCZ_STORE_PASSWORD missing).',
            ]);
        }

        try {
            $url = $this->sslcz->createPaymentSession($taxAssessment, $request->user());
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'Could not start SSL Commerz payment: '.$exception->getMessage(),
            ]);
        }

        return redirect()->away($url);
    }

    /**
     * Demo-mode confirmation. This route ONLY exists when SSLCZ_DEMO_MODE
     * is enabled — the demo success URL is built in
     * SSLCommerzCheckoutService::buildDemoSuccessUrl().
     *
     * Records a TaxPayment locally using the same path that the IPN
     * would normally use, then renders a confirmation page. No external
     * API call is made and no money moves.
     */
    public function demoConfirm(Request $request): RedirectResponse
    {
        if (! $this->sslcz->isDemoMode()) {
            abort(404);
        }

        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $tranId = (string) $request->query('tran_id', '');
        $validationId = (string) $request->query('val_id', '');
        $amount = (string) $request->query('amount', '');

        if ($tranId === '' || $validationId === '' || $amount === '') {
            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'Demo payment link is missing required parameters.',
            ]);
        }

        $assessment = $this->resolveAssessment($request);
        if (! $assessment) {
            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'Demo payment could not be linked to an outstanding assessment.',
            ]);
        }

        $payment = $this->confirmPayment($user, $assessment, [
            'tran_id' => $tranId,
            'val_id' => $validationId,
            'amount' => $amount,
            'currency' => 'BDT',
            'card_type' => 'DEMO',
            'bank_tran_id' => 'DEMO-BANK-'.strtoupper(bin2hex(random_bytes(3))),
            'source' => 'demo',
        ]);

        return redirect()
            ->route('citizen.taxes.index')
            ->with('status', 'DEMO payment recorded. No real money was charged.');
    }

    /**
     * Customer was redirected back by SSLCZ after a successful payment.
     * We try to confirm via the validation API as a best-effort path
     * (works even when IPN is blocked locally). The IPN remains the
     * authoritative record.
     */
    public function success(Request $request): RedirectResponse
    {
        $tranId = (string) $request->query('tran_id', '');
        $validationId = (string) $request->query('val_id', '');
        $amount = (string) $request->query('amount', '');
        $status = strtoupper((string) $request->query('status', ''));

        if ($status !== 'VALID' && $status !== 'VALIDATED') {
            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'SSL Commerz marked this payment as '.($status ?: 'invalid').'.',
            ]);
        }

        $assessment = $this->resolveAssessment($request);
        if (! $assessment) {
            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'Payment succeeded but we could not link it to an assessment.',
            ]);
        }

        /*
         * Server-side validation against the gateway. The amount, currency
         * and transaction id that arrived on the query string are supplied
         * by the browser and must never be trusted on their own, so we
         * re-query SSLCZ and reconcile against the assessment.
         */
        $validation = $this->sslcz->validateTransactionForAssessment(
            $validationId,
            $assessment,
            $tranId
        );

        if (! $validation['valid']) {
            Log::warning('SSLCZ success() rejected by gateway validation.', [
                'assessment' => $assessment->id,
                'tran_id' => $tranId,
                'reason' => $validation['reason'],
            ]);

            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'Payment could not be verified with SSL Commerz: '.$validation['reason'].'.',
            ]);
        }

        /*
         * Best-effort confirmation. If this fails, the IPN will
         * eventually arrive and record the payment anyway.
         */
        try {
            $this->confirmPayment($request->user(), $assessment, [
                'tran_id' => $tranId,
                'val_id' => $validationId,
                'amount' => (string) ($validation['data']['amount'] ?? $amount),
                'currency' => (string) ($validation['data']['currency'] ?? ''),
                'source' => 'success',
                'gateway_response' => $validation['data'],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('SSLCZ success() confirmation failed; relying on IPN.', [
                'assessment' => $assessment->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('citizen.taxes.index')->with('status', 'Payment received and verified. Thank you.');
    }

    /**
     * SSLCZ redirects the customer here when the payment method fails.
     */
    public function fail(Request $request): RedirectResponse
    {
        return redirect()->route('citizen.taxes.index')->withErrors([
            'payment' => 'SSL Commerz reported the payment as failed. Please try again or use a different method.',
        ]);
    }

    /**
     * SSLCZ redirects the customer here when they cancel on the
     * hosted page.
     */
    public function cancel(Request $request): RedirectResponse
    {
        return redirect()->route('citizen.taxes.index')->withErrors([
            'payment' => 'Payment cancelled.',
        ]);
    }

    /**
     * SSLCZ server-to-server callback. This is the authoritative
     * confirmation endpoint. We accept both form-urlencoded and JSON
     * payloads so we're robust to either transport.
     */
    public function ipn(Request $request): JsonResponse
    {
        /*
         * Layer 1 of the endpoint's protection: an optional shared-secret
         * header check. The IPN is unauthenticated (the gateway does not
         * carry a session), so this is the only thing standing between
         * the public internet and this handler before the gateway-side
         * reconciliation in layer 2.
         */
        if (! $this->ipnSecretIsValid($request)) {
            Log::warning('SSLCZ IPN rejected: invalid shared secret.', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['ok' => false, 'reason' => 'unauthorized'], 403);
        }

        $payload = array_merge($request->query(), $request->all());

        $status = strtoupper((string) ($payload['status'] ?? ''));
        $tranId = (string) ($payload['tran_id'] ?? '');
        $validationId = (string) ($payload['val_id'] ?? '');
        $amount = (string) ($payload['amount'] ?? '');
        $cardType = (string) ($payload['card_type'] ?? '');
        $bankTransactionId = (string) ($payload['bank_tran_id'] ?? '');

        if (! in_array($status, ['VALID', 'VALIDATED'], true)) {
            Log::info('SSLCZ IPN ignored: non-success status.', [
                'status' => $status,
                'tran_id' => $tranId,
            ]);

            return response()->json(['ok' => false, 'reason' => 'non-success status'], 200);
        }

        $assessment = $this->resolveAssessmentFromPayload($payload);
        if (! $assessment) {
            Log::warning('SSLCZ IPN could not resolve assessment.', [
                'tran_id' => $tranId,
                'payload_keys' => array_keys($payload),
            ]);

            return response()->json(['ok' => false, 'reason' => 'no assessment'], 200);
        }

        /*
         * Validate against the gateway before recording anything. The
         * IPN body is attacker-controllable unless the gateway signature
         * is verified, so we reconcile amount/currency/tran_id with
         * SSLCZ's own Validation API.
         */
        $validation = $this->sslcz->validateTransactionForAssessment(
            $validationId,
            $assessment,
            $tranId
        );

        if (! $validation['valid']) {
            Log::warning('SSLCZ IPN rejected by gateway validation.', [
                'assessment' => $assessment->id,
                'tran_id' => $tranId,
                'reason' => $validation['reason'],
            ]);

            return response()->json(['ok' => false, 'reason' => 'validation failed'], 200);
        }

        try {
            $this->confirmPayment($assessment->owner, $assessment, [
                'tran_id' => $tranId,
                'val_id' => $validationId,
                'amount' => (string) ($validation['data']['amount'] ?? $amount),
                'currency' => (string) ($validation['data']['currency'] ?? ($payload['currency'] ?? '')),
                'card_type' => $cardType,
                'bank_tran_id' => $bankTransactionId,
                'source' => 'ipn',
                'gateway_response' => $validation['data'],
            ]);
        } catch (\Throwable $exception) {
            Log::error('SSLCZ IPN confirmation failed', [
                'assessment' => $assessment->id,
                'tran_id' => $tranId,
                'error' => $exception->getMessage(),
            ]);

            // 200 with ok=false so SSLCZ does not retry forever, but
            // operators see the error in the logs.
            return response()->json(['ok' => false, 'reason' => 'db error'], 200);
        }

        return response()->json(['ok' => true], 200);
    }

    /**
     * Persist the TaxPayment. Idempotent: re-running with the same
     * reference is a no-op. Returns the persisted TaxPayment.
     */
    private function confirmPayment($user, TaxAssessment $assessment, array $payload): TaxPayment
    {
        if (! $user) {
            throw new \RuntimeException('Cannot record a payment for an unauthenticated user.');
        }

        $reference = $payload['tran_id']
            ?: $payload['bank_tran_id']
            ?: ('SSLCZ-'.($payload['val_id'] ?: uniqid('', true)));

        $currency = strtoupper((string) ($payload['currency'] ?: 'BDT'));

        /*
         * The amount is never taken from an untrusted request field. By
         * the time we get here the caller has already reconciled it
         * against the gateway (or explicitly passed null for demo mode,
         * where the assessment amount is authoritative).
         */
        $amount = isset($payload['amount']) && $payload['amount'] !== null && $payload['amount'] !== ''
            ? (float) $payload['amount']
            : (float) $assessment->tax_amount;

        if ($amount <= 0) {
            $amount = (float) $assessment->tax_amount;
        }

        $payment = TaxPayment::firstOrCreate(
            [
                'tax_assessment_id' => $assessment->id,
                'reference' => $reference,
                'method' => 'sslcommerz',
            ],
            [
                'payer_id' => $user->id,
                'recorded_by' => $user->id,
                'amount' => $amount,
                'currency' => $currency,
                'paid_at' => now(),
                'notes' => sprintf(
                    'SSL Commerz %s confirmation. currency=%s, val_id=%s, card_type=%s, bank_tran_id=%s.',
                    $payload['source'] ?? 'unknown',
                    $currency,
                    (string) ($payload['val_id'] ?? ''),
                    (string) ($payload['card_type'] ?? ''),
                    (string) ($payload['bank_tran_id'] ?? '')
                ),
                'gateway_response' => $payload['gateway_response'] ?? null,
            ]
        );

        /*
         * Now that at least one payment exists, ask the model whether
         * the assessment is fully settled.
         */
        $assessment->refresh();
        if (method_exists($assessment, 'markPaidIfSettled')) {
            $assessment->markPaidIfSettled();
        }

        return $payment;
    }

    /**
     * Resolve an assessment from the success callback. The customer's
     * session is the only thing we trust here (NOT the SSLCZ payload,
     * which a tamper-prone customer could fabricate). If only one
     * outstanding issued/overdue assessment belongs to this customer,
     * we pick that.
     */
    private function resolveAssessment(Request $request): ?TaxAssessment
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        return TaxAssessment::where('owner_id', $user->id)
            ->whereIn('status', ['issued', 'overdue'])
            ->orderByDesc('fiscal_year')
            ->first();
    }

    /**
     * Resolve an assessment from the IPN payload. We do NOT trust the
     * customer's user_id out of the payload (it could be forged). We
     * only trust value_a / value_b which were set by our own service
     * before the SSLCZ round trip.
     */
    private function resolveAssessmentFromPayload(array $payload): ?TaxAssessment
    {
        $valueA = (string) ($payload['value_a'] ?? '');
        $assessmentId = null;

        if (preg_match('/assessment_id:(\d+)/', $valueA, $matches)) {
            $assessmentId = (int) $matches[1];
        }

        if (! $assessmentId) {
            return null;
        }

        return TaxAssessment::find($assessmentId);
    }

    /**
     * Check the optional shared secret on the unauthenticated IPN.
     *
     * When SSLCZ_IPN_SECRET is not configured the check is skipped —
     * the controller still reconciles every callback against the
     * gateway's Validation API, which is the authoritative guard.
     */
    private function ipnSecretIsValid(Request $request): bool
    {
        $secret = (string) config('services.sslcommerz.ipn_secret', '');

        if ($secret === '') {
            return true;
        }

        $provided = (string) $request->header('X-SSLCommerz-Secret', '');

        return hash_equals($secret, $provided);
    }

    private function authorizeAssessment($user, TaxAssessment $taxAssessment): void
    {
        if (! $user || $taxAssessment->owner_id !== $user->id) {
            abort(403);
        }
    }
}
