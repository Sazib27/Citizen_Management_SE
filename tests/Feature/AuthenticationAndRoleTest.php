<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationAndRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_citizen_can_log_in_with_valid_credentials(): void
    {
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'email' => 'citizen@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->post(route('login.post'), [
            'email' => 'citizen@example.com',
            'password' => 'secret-password',
        ]);

        $this->assertAuthenticatedAs($citizen);
        $response->assertRedirect(route('citizen.dashboard'));
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        User::factory()->create([
            'role' => 'citizen',
            'email' => 'citizen@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $this->post(route('login.post'), [
            'email' => 'citizen@example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        User::factory()->create([
            'role' => 'citizen',
            'email' => 'citizen@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        // The limiter allows 5 attempts per minute for a given email.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.post'), [
                'email' => 'citizen@example.com',
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->post(route('login.post'), [
            'email' => 'citizen@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertStatus(429);
        $this->assertGuest();
    }

    public function test_citizen_cannot_reach_admin_routes(): void
    {
        $citizen = User::factory()->create(['role' => 'citizen']);

        $this->actingAs($citizen)
            ->get(route('admin.properties.index'))
            ->assertRedirect(route('citizen.dashboard'));
    }

    public function test_admin_cannot_reach_citizen_only_routes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('citizen.properties.index'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('citizen.dashboard'))->assertRedirect(route('login'));
    }

    public function test_unverified_citizen_is_blocked_from_verified_only_routes(): void
    {
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'pending',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($citizen)
            ->get(route('citizen.properties.index'))
            ->assertRedirect(route('citizen.dashboard'));
    }

    public function test_citizen_without_a_verified_email_is_sent_to_the_verification_notice(): void
    {
        $citizen = User::factory()->unverified()->create([
            'role' => 'citizen',
            'verification_status' => 'verified',
        ]);

        $this->actingAs($citizen)
            ->get(route('citizen.properties.index'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_logout_ends_the_session(): void
    {
        $citizen = User::factory()->create(['role' => 'citizen']);

        $this->actingAs($citizen)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_registration_creates_a_citizen_with_pending_verification(): void
    {
        $this->post(route('register.post'), [
            'name' => 'New Citizen',
            'email' => 'newcitizen@gmail.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'newcitizen@gmail.com',
            'role' => 'citizen',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'newcitizen@gmail.com',
            'verification_status' => 'pending',
        ]);
    }
}
