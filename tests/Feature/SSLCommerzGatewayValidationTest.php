<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\TaxAssessment;
use App\Models\TaxPayment;
use App\Models\User;
use App\Services\SSLCommerzCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Covers the server-side reconciliation between the payment gateway and
 * our own books.
 *
 * The callback amount/currency arrive on the request and are therefore
 * attacker-controllable — the whole point of these tests is that a
 * forged callback must not be able to mark an assessment as paid.
 */
class SSLCommerzGatewayValidationTest extends TestCase
{
    use RefreshDatabase;

    private function assessment(float $amount = 1500): TaxAssessment
    {
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'verified',
        ]);

        $property = Property::factory()->create(['owner_id' => $citizen->id]);

        return TaxAssessment::factory()->issued()->create([
            'owner_id' => $citizen->id,
            'property_id' => $property->id,
            'tax_amount' => $amount,
        ]);
    }

    public function test_ipn_is_rejected_when_the_gateway_reports_a_different_amount(): void
    {
        $assessment = $this->assessment(1500);

        $sslcz = Mockery::mock(SSLCommerzCheckoutService::class);
        $sslcz->shouldReceive('validateTransactionForAssessment')
            ->once()
            ->andReturn([
                'valid' => false,
                'reason' => 'amount mismatch: expected 1500.00, gateway reported 100.00',
                'data' => [],
            ]);

        $this->app->instance(SSLCommerzCheckoutService::class, $sslcz);

        $response = $this->post(route('citizen.taxes.payment.ipn'), [
            'status' => 'VALID',
            'tran_id' => 'TAX-1-1-ABCD',
            'amount' => '1500.00',
            'value_a' => 'assessment_id:'.$assessment->id,
        ]);

        $response->assertJson(['ok' => false]);

        $this->assertDatabaseCount('tax_payments', 0);
        $this->assertNotEquals('paid', $assessment->fresh()->status);
    }

    public function test_ipn_is_rejected_when_the_gateway_reports_a_different_currency(): void
    {
        $assessment = $this->assessment(1500);

        $sslcz = Mockery::mock(SSLCommerzCheckoutService::class);
        $sslcz->shouldReceive('validateTransactionForAssessment')
            ->once()
            ->andReturn([
                'valid' => false,
                'reason' => 'currency mismatch: expected BDT, got USD',
                'data' => [],
            ]);

        $this->app->instance(SSLCommerzCheckoutService::class, $sslcz);

        $this->post(route('citizen.taxes.payment.ipn'), [
            'status' => 'VALID',
            'tran_id' => 'TAX-1-1-ABCD',
            'amount' => '1500.00',
            'currency' => 'USD',
            'value_a' => 'assessment_id:'.$assessment->id,
        ])->assertJson(['ok' => false]);

        $this->assertDatabaseCount('tax_payments', 0);
    }

    public function test_ipn_is_rejected_when_the_gateway_status_is_not_valid(): void
    {
        $assessment = $this->assessment(1500);

        $sslcz = Mockery::mock(SSLCommerzCheckoutService::class);
        $sslcz->shouldReceive('validateTransactionForAssessment')
            ->once()
            ->andReturn([
                'valid' => false,
                'reason' => 'gateway status is FAILED',
                'data' => [],
            ]);

        $this->app->instance(SSLCommerzCheckoutService::class, $sslcz);

        $this->post(route('citizen.taxes.payment.ipn'), [
            'status' => 'VALID',
            'tran_id' => 'TAX-1-1-ABCD',
            'value_a' => 'assessment_id:'.$assessment->id,
        ])->assertJson(['ok' => false]);

        $this->assertDatabaseCount('tax_payments', 0);
    }

    public function test_ipn_records_the_payment_when_the_gateway_confirms_it(): void
    {
        $assessment = $this->assessment(1500);

        $sslcz = Mockery::mock(SSLCommerzCheckoutService::class);
        $sslcz->shouldReceive('validateTransactionForAssessment')
            ->once()
            ->andReturn([
                'valid' => true,
                'reason' => null,
                'data' => [
                    'status' => 'VALID',
                    'amount' => '1500.00',
                    'currency' => 'BDT',
                    'tran_id' => 'TAX-1-1-ABCD',
                ],
            ]);

        $this->app->instance(SSLCommerzCheckoutService::class, $sslcz);

        $this->post(route('citizen.taxes.payment.ipn'), [
            'status' => 'VALID',
            'tran_id' => 'TAX-1-1-ABCD',
            'val_id' => 'val_abcd',
            'amount' => '1500.00',
            'value_a' => 'assessment_id:'.$assessment->id,
        ])->assertJson(['ok' => true]);

        $this->assertDatabaseHas('tax_payments', [
            'tax_assessment_id' => $assessment->id,
            'reference' => 'TAX-1-1-ABCD',
            'method' => 'sslcommerz',
            'amount' => 1500,
            'currency' => 'BDT',
        ]);

        $this->assertEquals('paid', $assessment->fresh()->status);
    }

    public function test_ipn_is_idempotent_when_the_gateway_calls_back_twice(): void
    {
        $assessment = $this->assessment(1500);

        $sslcz = Mockery::mock(SSLCommerzCheckoutService::class);
        $sslcz->shouldReceive('validateTransactionForAssessment')
            ->twice()
            ->andReturn([
                'valid' => true,
                'reason' => null,
                'data' => ['amount' => '1500.00', 'currency' => 'BDT'],
            ]);

        $this->app->instance(SSLCommerzCheckoutService::class, $sslcz);

        $payload = [
            'status' => 'VALID',
            'tran_id' => 'TAX-1-1-DUPE',
            'val_id' => 'val_dupe',
            'amount' => '1500.00',
            'value_a' => 'assessment_id:'.$assessment->id,
        ];

        $this->post(route('citizen.taxes.payment.ipn'), $payload)->assertJson(['ok' => true]);
        $this->post(route('citizen.taxes.payment.ipn'), $payload)->assertJson(['ok' => true]);

        $this->assertEquals(1, TaxPayment::count());
    }

    public function test_ipn_requires_the_shared_secret_when_one_is_configured(): void
    {
        config(['services.sslcommerz.ipn_secret' => 'a-known-secret']);

        $response = $this->post(route('citizen.taxes.payment.ipn'), [
            'status' => 'VALID',
            'tran_id' => 'TAX-1-1-NOSECRET',
            'value_a' => 'assessment_id:1',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('tax_payments', 0);
    }

    public function test_ipn_accepts_a_request_carrying_the_correct_shared_secret(): void
    {
        config(['services.sslcommerz.ipn_secret' => 'a-known-secret']);

        $assessment = $this->assessment(1500);

        $sslcz = Mockery::mock(SSLCommerzCheckoutService::class);
        $sslcz->shouldReceive('validateTransactionForAssessment')
            ->once()
            ->andReturn([
                'valid' => true,
                'reason' => null,
                'data' => ['amount' => '1500.00', 'currency' => 'BDT'],
            ]);

        $this->app->instance(SSLCommerzCheckoutService::class, $sslcz);

        $this->withHeader('X-SSLCommerz-Secret', 'a-known-secret')
            ->post(route('citizen.taxes.payment.ipn'), [
                'status' => 'VALID',
                'tran_id' => 'TAX-1-1-SECRET',
                'value_a' => 'assessment_id:'.$assessment->id,
            ])
            ->assertJson(['ok' => true]);
    }

    public function test_ipn_route_does_not_require_a_session(): void
    {
        // The gateway posts server-to-server with no session cookie, so
        // the route must not sit behind the auth middleware.
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->getName() === 'citizen.taxes.payment.ipn');

        $this->assertNotNull($route);
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('citizen', $route->gatherMiddleware());
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
