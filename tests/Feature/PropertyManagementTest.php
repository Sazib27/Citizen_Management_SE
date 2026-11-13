<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyRequest;
use App\Models\RentAgreement;
use App\Models\TaxAssessment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_citizen_can_submit_property_add_request(): void
    {
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'verified',
        ]);

        $response = $this->actingAs($citizen)
            ->post(route('citizen.properties.request.add.store'), [
                'title' => 'Test Property',
                'type' => 'residential',
                'address_line' => '123 Example Street',
                'city' => 'Dhaka',
                'assessed_value' => 5500000,
                'land_use' => 'residential',
                'last_valuation_at' => '2025-01-01',
            ]);

        $response->assertRedirect(route('citizen.properties.index'));
        $this->assertDatabaseHas('property_requests', [
            'user_id' => $citizen->id,
            'type' => 'add',
            'status' => 'pending',
        ]);

        $payload = PropertyRequest::first()->payload;
        $this->assertEquals(5500000, $payload['assessed_value']);
        $this->assertEquals('residential', $payload['land_use']);
        $this->assertEquals('2025-01-01', $payload['last_valuation_at']);
    }

    public function test_unverified_citizen_is_redirected_from_property_pages(): void
    {
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'pending',
        ]);

        $this->actingAs($citizen)
            ->get(route('citizen.properties.index'))
            ->assertRedirect(route('citizen.dashboard'));
    }

    public function test_admin_can_create_property_directly(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.properties.store'), [
                'title' => 'Managed Property',
                'type' => 'commercial',
                'assessed_value' => 7500000,
                'land_use' => 'commercial',
                'last_valuation_at' => '2025-02-15',
            ]);

        $response->assertRedirect(route('admin.properties.index'));
        $this->assertDatabaseHas('properties', [
            'title' => 'Managed Property',
            'type' => 'commercial',
            'assessed_value' => 7500000,
            'land_use' => 'commercial',
        ]);

        $this->assertEquals('2025-02-15 00:00:00', Property::first()->last_valuation_at?->format('Y-m-d H:i:s'));
    }

    public function test_admin_cannot_delete_a_property_with_an_active_rent_agreement(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $tenant = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);

        $property = Property::factory()->for($owner, 'owner')->create();

        RentAgreement::factory()->for($property)->create([
            'tenant_id' => $tenant->id,
            'landlord_id' => $owner->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.properties.destroy', $property))
            ->assertSessionHasErrors('property');

        $this->assertDatabaseHas('properties', ['id' => $property->id]);
    }

    public function test_admin_cannot_delete_a_property_with_an_outstanding_tax_assessment(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);

        $property = Property::factory()->for($owner, 'owner')->create();

        TaxAssessment::factory()->issued()->create([
            'owner_id' => $owner->id,
            'property_id' => $property->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.properties.destroy', $property))
            ->assertSessionHasErrors('property');

        $this->assertDatabaseHas('properties', ['id' => $property->id]);
    }

    public function test_admin_can_delete_a_property_with_no_live_obligations(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $property = Property::factory()->for($owner, 'owner')->create();

        $this->actingAs($admin)
            ->delete(route('admin.properties.destroy', $property))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('properties', ['id' => $property->id]);
    }

    public function test_citizen_search_matches_the_property_address(): void
    {
        $citizen = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $owner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);

        Property::factory()->for($owner, 'owner')->create([
            'title' => 'Sunset Villa',
            'address_line' => '42 Rokeya Sarani',
            'city' => 'Dhaka',
        ]);

        $this->actingAs($citizen)
            ->get(route('citizen.properties.index', ['q' => 'Rokeya']))
            ->assertOk()
            ->assertSee('Sunset Villa');
    }

    public function test_citizen_search_matches_the_owner_name(): void
    {
        $citizen = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $owner = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'verified',
            'full_name' => 'Distinctive Owner Name',
        ]);

        Property::factory()->for($owner, 'owner')->create([
            'title' => 'Searchable Property',
        ]);

        $this->actingAs($citizen)
            ->get(route('citizen.properties.index', ['q' => 'Distinctive Owner']))
            ->assertOk()
            ->assertSee('Searchable Property');
    }
}
