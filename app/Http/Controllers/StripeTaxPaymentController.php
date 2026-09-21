<?php

namespace App\Http\Controllers;

use App\Models\TaxAssessment;
use App\Models\TaxPayment;
use App\Services\StripeCheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Stripe\Exception\ApiErrorException;

class StripeTaxPaymentController extends Controller
{
    public function __construct(private readonly StripeCheckoutService $stripe)
    {
    }

    public function create(Request $request, TaxAssessment $taxAssessment): RedirectResponse
    {
        $this->authorizeAssessment($request->user(), $taxAssessment);

        if (!in_array($taxAssessment->status, ['issued', 'overdue'])) {
            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'This assessment is not eligible for online payment.',
            ]);
        }

        /*
         * Stripe API key may be missing or still set to the placeholder
         * shipped with the repo. Surface a clear, actionable error before
         * wasting a round trip to Stripe.
         */
        $stripeSecret = (string) config('services.stripe.secret', '');
        if ($stripeSecret === '' || str_contains($stripeSecret, 'placeholder')) {
            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'Stripe is not configured on this server. Please contact the administrator (STRIPE_SECRET missing).',
            ]);
        }

        try {
            $session = $this->stripe->createPaymentSession($taxAssessment, $request->user());
        } catch (ApiErrorException $exception) {
            report($exception);

            /*
             * Surface the real Stripe error message in the flash so the
             * user (and operator reading the flash) knows what actually
             * went wrong, instead of a generic "try again".
             */
            $stripeMessage = $exception->getMessage() ?: 'Unknown Stripe error.';

            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'Stripe rejected the payment request: ' . $stripeMessage,
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'Unable to start Stripe payment. Please try again later.',
            ]);
        }

        return redirect()->away($session->url);
    }

    public function success(Request $request): RedirectResponse
    {
        $sessionId = $request->query('session_id');
        if (!$sessionId) {
            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'Missing payment session. Please try again.',
            ]);
        }

        try {
            $session = $this->stripe->retrieveSession($sessionId);
        } catch (ApiErrorException $exception) {
            report($exception);

            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'Unable to verify payment with Stripe.',
            ]);
        }

        if (($session->payment_status ?? null) !== 'paid') {
            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'Stripe marked this session as unpaid. No changes were made.',
            ]);
        }

        $assessmentId = $session->metadata->assessment_id ?? null;
        if (!$assessmentId) {
            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'Payment succeeded but we could not find the linked assessment. Please contact support with your receipt.',
            ]);
        }

        $user = Auth::user();

        $assessment = TaxAssessment::where('id', $assessmentId)
            ->where('owner_id', $user->id)
            ->firstOrFail();

        $reference = $this->resolveReference($session);
        $amount = $this->fromStripeAmount($session->amount_total ?? 0, $assessment->tax_amount);

        TaxPayment::firstOrCreate(
            [
                'tax_assessment_id' => $assessment->id,
                'reference' => $reference,
                'method' => 'stripe_checkout',
            ],
            [
                'payer_id' => $user->id,
                'recorded_by' => $user->id,
                'amount' => $amount,
                'paid_at' => now(),
                'notes' => 'Stripe Checkout payment recorded automatically.',
            ]
        );

        $assessment->refresh();
        $assessment->markPaidIfSettled();

        return redirect()->route('citizen.taxes.index')->with('status', 'Payment confirmed and recorded.');
    }

    private function authorizeAssessment($user, TaxAssessment $taxAssessment): void
    {
        if (!$user || $taxAssessment->owner_id !== $user->id) {
            abort(403);
        }
    }

    private function fromStripeAmount(int $amountTotal, float $fallback): float
    {
        if ($amountTotal <= 0) {
            return $fallback;
        }

        return round($amountTotal / 100, 2);
    }

    private function resolveReference($session): string
    {
        $intent = $session->payment_intent ?? null;

        if (is_object($intent)) {
            return (string) ($intent->id ?? $session->id);
        }

        if (is_string($intent) && $intent !== '') {
            return $intent;
        }

        return (string) ($session->id ?? uniqid('stripe_', true));
    }
}
