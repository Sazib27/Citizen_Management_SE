<?php

namespace Tests\Unit;

use App\Models\TaxAssessment;
use App\Services\SSLCommerzCheckoutService;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit coverage of the gateway reconciliation rules.
 *
 * These exercise the decision logic of
 * SSLCommerzCheckoutService::validateTransactionForAssessment() without
 * booting the framework or touching the network.
 */
class SSLCommerzValidationRulesTest extends TestCase
{
    private function service(bool $demoMode = false): SSLCommerzCheckoutService
    {
        return new SSLCommerzCheckoutService(
            storeId: 'test-store',
            storePassword: 'test-password',
            currency: 'BDT',
            successUrl: 'https://example.test/success',
            failUrl: 'https://example.test/fail',
            cancelUrl: 'https://example.test/cancel',
            ipnUrl: 'https://example.test/ipn',
            sandbox: true,
            connectTimeout: 5,
            demoMode: $demoMode,
        );
    }

    private function assessment(float $amount = 1500.00): TaxAssessment
    {
        $assessment = new TaxAssessment;
        $assessment->tax_amount = $amount;

        return $assessment;
    }

    public function test_demo_mode_short_circuits_validation(): void
    {
        $result = $this->service(demoMode: true)
            ->validateTransactionForAssessment('', $this->assessment());

        $this->assertTrue($result['valid']);
        $this->assertNull($result['reason']);
    }

    public function test_a_missing_validation_id_is_rejected(): void
    {
        $result = $this->service()
            ->validateTransactionForAssessment('   ', $this->assessment());

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('missing validation id', $result['reason']);
    }

    public function test_the_service_exposes_its_configuration(): void
    {
        $service = $this->service();

        $this->assertTrue($service->isConfigured());
        $this->assertTrue($service->isSandbox());
        $this->assertFalse($service->isDemoMode());
    }

    public function test_an_unconfigured_service_reports_itself_as_unconfigured(): void
    {
        $service = new SSLCommerzCheckoutService(
            storeId: '',
            storePassword: '',
            currency: 'BDT',
            successUrl: 'https://example.test/success',
            failUrl: 'https://example.test/fail',
            cancelUrl: 'https://example.test/cancel',
            ipnUrl: 'https://example.test/ipn',
        );

        $this->assertFalse($service->isConfigured());
    }
}
