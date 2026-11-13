<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\RentAgreement;
use App\Models\RentalRequest;
use App\Models\User;
use App\Notifications\RentAgreementGenerated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RentAgreementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_approval_generates_rent_agreement(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $tenant = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $property = Property::factory()->for($owner, 'owner')->create();

        $rentalRequest = RentalRequest::create([
            'property_id' => $property->id,
            'user_id' => $tenant->id,
            'status' => 'pending',
            // The property owner has confirmed the terms, which is what
            // makes the request visible to / actionable by the admin.
            'ready_for_admin' => true,
            'owner_confirmed_at' => now(),
            'owner_confirmed_by' => $owner->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.rental-requests.handle', $rentalRequest), [
                'action' => 'approve',
                'start_date' => now()->format('Y-m-d'),
                'end_date' => now()->addMonths(12)->format('Y-m-d'),
                'monthly_rent' => 15000,
                'security_deposit' => 30000,
            ])
            ->assertSessionHas('status');

        $agreement = RentAgreement::first();

        $this->assertNotNull($agreement);
        $this->assertEquals($tenant->id, $agreement->tenant_id);
        $this->assertEquals($owner->id, $agreement->landlord_id);
        $this->assertEquals(15000.0, (float) $agreement->monthly_rent);
    }

    public function test_admin_can_approve_a_request_the_owner_never_confirmed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $tenant = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $property = Property::factory()->for($owner, 'owner')->create();

        $rentalRequest = RentalRequest::create([
            'property_id' => $property->id,
            'user_id' => $tenant->id,
            'status' => 'pending',
            'ready_for_admin' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.rental-requests.handle', $rentalRequest), [
                'action' => 'approve',
                'start_date' => now()->format('Y-m-d'),
                'end_date' => now()->addMonths(12)->format('Y-m-d'),
                'monthly_rent' => 15000,
            ])
            ->assertSessionHas('status');

        $this->assertNotNull(RentAgreement::first());
    }

    public function test_pending_rental_requests_are_visible_to_the_admin_before_the_owner_confirms(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $tenant = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $property = Property::factory()->for($owner, 'owner')->create();

        $notConfirmed = RentalRequest::create([
            'property_id' => $property->id,
            'user_id' => $tenant->id,
            'status' => 'pending',
            'ready_for_admin' => false,
            'message' => 'UNIQUE-MARKER-not-owner-confirmed',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.properties.rentals'))
            ->assertOk()
            ->assertSee('UNIQUE-MARKER-not-owner-confirmed')
            ->assertSee('Waiting for owner confirmation');
    }

    public function test_admin_panel_lists_a_request_for_a_property_with_no_owner(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tenant = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $property = Property::factory()->create(['owner_id' => null]);

        RentalRequest::create([
            'property_id' => $property->id,
            'user_id' => $tenant->id,
            'status' => 'pending',
            'ready_for_admin' => false,
            'message' => 'UNIQUE-MARKER-unassigned-property',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.properties.rentals'))
            ->assertOk()
            ->assertSee('UNIQUE-MARKER-unassigned-property')
            ->assertSee('Unassigned');
    }

    public function test_an_unverified_owner_can_still_confirm_a_rental_request(): void
    {
        /*
         * The owner of property 4 in the dev database has a `rejected`
         * citizen verification, so every property route redirects them
         * away and they can never confirm a request. The owner queue is
         * deliberately outside the `verified.citizen` middleware group.
         */
        $owner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'rejected']);
        $tenant = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $property = Property::factory()->for($owner, 'owner')->create();

        $rentalRequest = RentalRequest::create([
            'property_id' => $property->id,
            'user_id' => $tenant->id,
            'status' => 'pending',
            'ready_for_admin' => false,
        ]);

        $this->actingAs($owner)
            ->get(route('citizen.properties.owner-rental-requests'))
            ->assertOk()
            ->assertSee($tenant->display_name);

        $this->actingAs($owner)
            ->post(route('citizen.rental-requests.owner-confirm', $rentalRequest), [
                'owner_start_date' => now()->format('Y-m-d'),
                'owner_end_date' => now()->addMonths(12)->format('Y-m-d'),
                'owner_monthly_rent' => 15000,
            ])
            ->assertSessionHas('status');

        $rentalRequest->refresh();

        $this->assertTrue((bool) $rentalRequest->ready_for_admin);
        $this->assertEquals($owner->id, $rentalRequest->owner_confirmed_by);
    }

    public function test_owner_cannot_confirm_a_request_for_someone_elses_property(): void
    {
        $owner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'rejected']);
        $otherOwner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $tenant = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $property = Property::factory()->for($otherOwner, 'owner')->create();

        $rentalRequest = RentalRequest::create([
            'property_id' => $property->id,
            'user_id' => $tenant->id,
            'status' => 'pending',
            'ready_for_admin' => false,
        ]);

        $this->actingAs($owner)
            ->post(route('citizen.rental-requests.owner-confirm', $rentalRequest), [
                'owner_start_date' => now()->format('Y-m-d'),
                'owner_end_date' => now()->addMonths(12)->format('Y-m-d'),
                'owner_monthly_rent' => 15000,
            ])
            ->assertForbidden();
    }

    public function test_tenant_can_view_own_agreement(): void
    {
        $tenant = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $owner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $property = Property::factory()->for($owner, 'owner')->create();

        $agreement = RentAgreement::factory()->for($property)->create([
            'tenant_id' => $tenant->id,
            'landlord_id' => $owner->id,
        ]);

        $this->actingAs($tenant)
            ->get(route('citizen.rent-agreements.show', $agreement))
            ->assertOk()
            ->assertSee($agreement->agreement_number);
    }

    public function test_generating_an_agreement_notifies_both_parties(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $tenant = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $property = Property::factory()->for($owner, 'owner')->create();

        $rentalRequest = RentalRequest::create([
            'property_id' => $property->id,
            'user_id' => $tenant->id,
            'status' => 'pending',
            'ready_for_admin' => true,
            'owner_confirmed_at' => now(),
            'owner_confirmed_by' => $owner->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.rental-requests.handle', $rentalRequest), [
                'action' => 'approve',
                'start_date' => now()->format('Y-m-d'),
                'end_date' => now()->addMonths(12)->format('Y-m-d'),
                'monthly_rent' => 15000,
            ]);

        Notification::assertSentTo([$tenant, $owner], RentAgreementGenerated::class);
    }

    public function test_tenant_can_download_the_agreement_pdf(): void
    {
        $tenant = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $owner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $property = Property::factory()->for($owner, 'owner')->create();

        $agreement = RentAgreement::factory()->for($property)->create([
            'tenant_id' => $tenant->id,
            'landlord_id' => $owner->id,
        ]);

        $this->actingAs($tenant)
            ->get(route('citizen.rent-agreements.download', $agreement))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_stranger_cannot_download_the_agreement_pdf(): void
    {
        $tenant = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $owner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $stranger = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $property = Property::factory()->for($owner, 'owner')->create();

        $agreement = RentAgreement::factory()->for($property)->create([
            'tenant_id' => $tenant->id,
            'landlord_id' => $owner->id,
        ]);

        $this->actingAs($stranger)
            ->get(route('citizen.rent-agreements.download', $agreement))
            ->assertForbidden();
    }

    public function test_an_agreement_can_be_found_by_its_number(): void
    {
        $tenant = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $owner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $property = Property::factory()->for($owner, 'owner')->create();

        $agreement = RentAgreement::factory()->for($property)->create([
            'tenant_id' => $tenant->id,
            'landlord_id' => $owner->id,
        ]);

        $this->actingAs($tenant)
            ->get(route('citizen.rent-agreements.lookup', ['agreement_number' => $agreement->agreement_number]))
            ->assertRedirect(route('citizen.rent-agreements.show', $agreement));
    }

    public function test_lookup_by_number_refuses_an_agreement_belonging_to_someone_else(): void
    {
        $tenant = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $owner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $stranger = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $property = Property::factory()->for($owner, 'owner')->create();

        $agreement = RentAgreement::factory()->for($property)->create([
            'tenant_id' => $tenant->id,
            'landlord_id' => $owner->id,
        ]);

        $this->actingAs($stranger)
            ->get(route('citizen.rent-agreements.lookup', ['agreement_number' => $agreement->agreement_number]))
            ->assertForbidden();
    }
}
