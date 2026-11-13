<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CertificateVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_certificate_is_issued_when_the_admin_approves_a_citizen(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'pending',
            'nid_number' => '1990123456',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.verification.approve', $citizen))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('certificates', [
            'user_id' => $citizen->id,
            'status' => Certificate::STATUS_VALID,
        ]);
    }

    public function test_certificate_number_is_unique_and_unguessable(): void
    {
        $numbers = collect(range(1, 25))->map(function () {
            return Certificate::generateNumber();
        });

        $this->assertCount(25, $numbers->unique());

        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('/^VCN-[A-Z0-9]{8}$/', $number);
        }
    }

    public function test_certificate_number_does_not_derive_from_the_user_id(): void
    {
        $user = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'verified',
            'nid_number' => '1990123456',
        ]);

        $certificate = Certificate::issueFor($user);

        $this->assertNotSame(sprintf('VCN-%06d', $user->id), $certificate->certificate_number);
    }

    public function test_public_endpoint_confirms_a_valid_certificate(): void
    {
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'verified',
            'nid_number' => '1990123456',
        ]);

        $certificate = Certificate::issueFor($citizen);

        $url = URL::temporarySignedRoute('verification.certificate.verify', now()->addHour(), [
            'certificate' => $certificate->certificate_number,
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('This certificate is valid')
            ->assertSee($certificate->certificate_number);
    }

    public function test_public_endpoint_reports_an_unknown_certificate(): void
    {
        $url = URL::temporarySignedRoute('verification.certificate.verify', now()->addHour(), [
            'certificate' => 'VCN-DOESNOTEXIST',
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Certificate not found');
    }

    public function test_public_endpoint_rejects_an_unsigned_request(): void
    {
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'verified',
        ]);

        $certificate = Certificate::issueFor($citizen);

        $this->get('/verification/certificate/'.$certificate->certificate_number)
            ->assertForbidden();
    }

    public function test_public_endpoint_reports_a_revoked_certificate(): void
    {
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'verified',
        ]);

        $certificate = Certificate::issueFor($citizen);
        $certificate->update([
            'status' => Certificate::STATUS_REVOKED,
            'revoked_at' => now(),
            'revocation_reason' => 'Identity could not be confirmed.',
        ]);

        $url = URL::temporarySignedRoute('verification.certificate.verify', now()->addHour(), [
            'certificate' => $certificate->certificate_number,
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('This certificate is not valid');
    }

    public function test_issuing_a_new_certificate_revokes_the_previous_one(): void
    {
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'verified',
        ]);

        $first = Certificate::issueFor($citizen);
        $second = Certificate::issueFor($citizen);

        $this->assertNotEquals($first->certificate_number, $second->certificate_number);
        $this->assertSame(Certificate::STATUS_REVOKED, $first->fresh()->status);
        $this->assertSame(Certificate::STATUS_VALID, $second->fresh()->status);
    }

    public function test_verified_citizen_can_download_the_certificate_pdf(): void
    {
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'verified',
            'nid_number' => '1990123456',
        ]);

        Certificate::issueFor($citizen);

        $this->actingAs($citizen)
            ->get(route('citizen.verification.certificate'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
