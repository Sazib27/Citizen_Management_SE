<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\TaxAssessment;
use App\Models\User;
use App\Services\SSLCommerzCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CitizenSSLCommerzPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_citizen_can_start_sslcommerz_checkout_for_outstanding_assessment(): void
    {
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'verified',
        ]);

        $property = Property::factory()->create(['owner_id' => $citizen->id]);
        $assessment = TaxAssessment::factory()->issued()->create([
            'owner_id' => $citizen->id,
            'property_id' => $property->id,
        ]);

        $sslcz = Mockery::mock(SSLCommerzCheckoutService::class);
        $sslcz->shouldReceive('isConfigured')->andReturn(true);
        $sslcz->shouldReceive('createPaymentSession')
            ->once()
            ->andReturn('https://sandbox.sslcommerz.test/gwprocess');

        $this->app->instance(SSLCommerzCheckoutService::class, $sslcz);

        $this->actingAs($citizen)
            ->post(route('citizen.taxes.pay', $assessment))
            ->assertRedirect('https://sandbox.sslcommerz.test/gwprocess');
    }

    public function test_unconfigured_sslcommerz_redirects_with_friendly_error(): void
    {
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'verified',
        ]);

        $property = Property::factory()->create(['owner_id' => $citizen->id]);
        $assessment = TaxAssessment::factory()->issued()->create([
            'owner_id' => $citizen->id,
            'property_id' => $property->id,
        ]);

        $sslcz = Mockery::mock(SSLCommerzCheckoutService::class);
        $sslcz->shouldReceive('isConfigured')->andReturn(false);
        $sslcz->shouldNotReceive('createPaymentSession');

        $this->app->instance(SSLCommerzCheckoutService::class, $sslcz);

        $response = $this->actingAs($citizen)
            ->post(route('citizen.taxes.pay', $assessment))
            ->assertRedirect(route('citizen.taxes.index'));

        $response->assertSessionHasErrors('payment');
        $this->assertStringContainsString(
            'SSL Commerz is not configured',
            (string) session('errors')->first()
        );
    }

    public function test_service_failure_is_surfaced_as_user_facing_error(): void
    {
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'verified',
        ]);

        $property = Property::factory()->create(['owner_id' => $citizen->id]);
        $assessment = TaxAssessment::factory()->issued()->create([
            'owner_id' => $citizen->id,
            'property_id' => $property->id,
        ]);

        $sslcz = Mockery::mock(SSLCommerzCheckoutService::class);
        $sslcz->shouldReceive('isConfigured')->andReturn(true);
        $sslcz->shouldReceive('createPaymentSession')
            ->once()
            ->andThrow(new RuntimeException('Curl error: SSL handshake failed'));

        $this->app->instance(SSLCommerzCheckoutService::class, $sslcz);

        $response = $this->actingAs($citizen)
            ->post(route('citizen.taxes.pay', $assessment))
            ->assertRedirect(route('citizen.taxes.index'));

        $response->assertSessionHasErrors('payment');
        $this->assertStringContainsString(
            'SSL handshake failed',
            (string) session('errors')->first()
        );
    }

    public function test_ipn_records_payment_for_matching_assessment(): void
    {
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'verified',
        ]);

        $property = Property::factory()->create(['owner_id' => $citizen->id]);
        $assessment = TaxAssessment::factory()->issued()->create([
            'owner_id' => $citizen->id,
            'property_id' => $property->id,
            'tax_amount' => 1500,
        ]);

        $payload = [
            'status' => 'VALID',
            'tran_id' => 'TAX-1-1-ABCD',
            'val_id' => 'val_abcd',
            'amount' => '1500.00',
            'currency' => 'BDT',
            'card_type' => 'VISA',
            'bank_tran_id' => 'BANK12345',
            'value_a' => 'assessment_id:'.$assessment->id,
        ];

        $this->post(route('citizen.taxes.payment.ipn'), $payload)
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('tax_payments', [
            'tax_assessment_id' => $assessment->id,
            'reference' => 'TAX-1-1-ABCD',
            'method' => 'sslcommerz',
        ]);

        $this->assertEquals('paid', $assessment->fresh()->status);
    }

    public function test_ipn_ignores_unknown_assessment(): void
    {
        $response = $this->post(route('citizen.taxes.payment.ipn'), [
            'status' => 'VALID',
            'tran_id' => 'TAX-999-1-DEAD',
            'value_a' => 'assessment_id:999999',
        ]);

        $response->assertJson(['ok' => false]);
    }

    public function test_ipn_ignores_non_valid_status(): void
    {
        $response = $this->post(route('citizen.taxes.payment.ipn'), [
            'status' => 'FAILED',
            'tran_id' => 'TAX-1-1-BEEF',
            'value_a' => 'assessment_id:1',
        ]);

        $response->assertJson(['ok' => false]);
        $this->assertDatabaseMissing('tax_payments', ['method' => 'sslcommerz']);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
