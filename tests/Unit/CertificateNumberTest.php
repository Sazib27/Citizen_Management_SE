<?php

namespace Tests\Unit;

use App\Models\Certificate;
use PHPUnit\Framework\TestCase;

class CertificateNumberTest extends TestCase
{
    public function test_generated_numbers_use_the_expected_format(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertMatchesRegularExpression('/^VCN-[A-Z0-9]{8}$/', Certificate::generateNumber());
        }
    }

    public function test_generated_numbers_are_not_repeated(): void
    {
        $numbers = [];

        for ($i = 0; $i < 200; $i++) {
            $numbers[] = Certificate::generateNumber();
        }

        $this->assertCount(200, array_unique($numbers));
    }
}
