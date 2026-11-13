<?php

namespace Tests\Feature;

use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplaintWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_citizen_can_submit_a_complaint_and_it_starts_open(): void
    {
        $citizen = User::factory()->create([
            'role' => 'citizen',
            'verification_status' => 'verified',
        ]);

        $this->actingAs($citizen)
            ->post(route('citizen.complaints.store'), [
                'subject' => 'Broken streetlight',
                'description' => 'The streetlight outside my house has not worked for a week.',
                'category' => 'Streetlight',
            ])
            ->assertRedirect(route('citizen.complaints.index'));

        $complaint = Complaint::first();

        $this->assertNotNull($complaint);
        $this->assertEquals($citizen->id, $complaint->user_id);
        $this->assertSame(ComplaintStatus::Open, $complaint->status);
    }

    public function test_submitting_a_complaint_opens_a_timeline_entry(): void
    {
        $citizen = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);

        $this->actingAs($citizen)
            ->post(route('citizen.complaints.store'), [
                'subject' => 'Drainage blocked',
                'description' => 'Water is backing up on the main road.',
            ]);

        $complaint = Complaint::first();

        $this->assertCount(1, $complaint->updates);
        $this->assertSame(ComplaintStatus::Open, $complaint->updates->first()->to_status);
    }

    public function test_citizen_cannot_submit_a_complaint_for_a_property_they_do_not_own(): void
    {
        $citizen = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $otherOwner = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $property = \App\Models\Property::factory()->for($otherOwner, 'owner')->create();

        $this->actingAs($citizen)
            ->post(route('citizen.complaints.store'), [
                'subject' => 'Trespassing',
                'description' => 'Someone is using my land.',
                'property_id' => $property->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('complaints', 0);
    }

    public function test_admin_can_progress_a_complaint_and_the_timeline_records_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $citizen = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);

        $complaint = Complaint::factory()->create(['user_id' => $citizen->id]);

        $this->actingAs($admin)
            ->put(route('admin.complaints.update', $complaint), [
                'status' => ComplaintStatus::InProgress->value,
                'admin_reply' => 'A team has been assigned to inspect the site.',
                'note' => 'Assigned to ward engineer.',
            ])
            ->assertRedirect(route('admin.complaints.show', $complaint));

        $complaint->refresh();

        $this->assertSame(ComplaintStatus::InProgress, $complaint->status);

        // The factory does not open a timeline entry, so this is the
        // first (and only) transition recorded.
        $this->assertCount(1, $complaint->updates);
        $this->assertSame(ComplaintStatus::InProgress, $complaint->updates->last()->to_status);
        $this->assertSame(ComplaintStatus::Open, $complaint->updates->last()->from_status);
        $this->assertEquals('Assigned to ward engineer.', $complaint->updates->last()->note);
    }

    public function test_admin_can_reject_a_complaint_but_must_give_a_reason(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $complaint = Complaint::factory()->create();

        // Without a reason the rejection is refused.
        $this->actingAs($admin)
            ->put(route('admin.complaints.update', $complaint), [
                'status' => ComplaintStatus::Rejected->value,
            ])
            ->assertSessionHasErrors('admin_reply');

        $this->assertSame(ComplaintStatus::Open, $complaint->fresh()->status);

        // With a reason it goes through.
        $this->actingAs($admin)
            ->put(route('admin.complaints.update', $complaint), [
                'status' => ComplaintStatus::Rejected->value,
                'admin_reply' => 'This matter falls outside the municipal mandate.',
            ])
            ->assertRedirect(route('admin.complaints.show', $complaint));

        $complaint->refresh();

        $this->assertSame(ComplaintStatus::Rejected, $complaint->status);
        $this->assertNotNull($complaint->closed_at);
    }

    public function test_resolved_complaint_can_be_reopened(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $complaint = Complaint::factory()->resolved()->create();

        $this->actingAs($admin)
            ->put(route('admin.complaints.update', $complaint), [
                'status' => ComplaintStatus::Open->value,
            ])
            ->assertRedirect(route('admin.complaints.show', $complaint));

        $complaint->refresh();

        $this->assertSame(ComplaintStatus::Open, $complaint->status);
        $this->assertNull($complaint->resolved_at);
        $this->assertNull($complaint->closed_at);
    }

    public function test_citizen_sees_the_status_timeline_on_the_complaint(): void
    {
        $citizen = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $complaint = Complaint::factory()->create(['user_id' => $citizen->id]);
        $complaint->logStatusChange(null, ComplaintStatus::Open, 'Complaint submitted.', $citizen->id);

        $this->actingAs($citizen)
            ->get(route('citizen.complaints.show', $complaint))
            ->assertOk()
            ->assertSee('Progress Timeline')
            ->assertSee('Complaint submitted.');
    }

    public function test_citizen_cannot_view_someone_elses_complaint(): void
    {
        $citizen = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $complaint = Complaint::factory()->create();

        $this->actingAs($citizen)
            ->get(route('citizen.complaints.show', $complaint))
            ->assertForbidden();
    }

    public function test_citizen_can_rate_a_resolved_complaint(): void
    {
        $citizen = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $complaint = Complaint::factory()->resolved()->create(['user_id' => $citizen->id]);

        $this->actingAs($citizen)
            ->post(route('citizen.complaints.rate', $complaint), ['rating' => 4])
            ->assertSessionHas('status');

        $complaint->refresh();

        $this->assertEquals(4, $complaint->rating);
        $this->assertNotNull($complaint->rated_at);
    }

    public function test_citizen_cannot_rate_an_open_complaint(): void
    {
        $citizen = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $complaint = Complaint::factory()->create(['user_id' => $citizen->id]);

        $this->actingAs($citizen)
            ->post(route('citizen.complaints.rate', $complaint), ['rating' => 5])
            ->assertSessionHasErrors('rating');

        $this->assertNull($complaint->fresh()->rating);
    }

    public function test_citizen_can_filter_their_complaints_by_status(): void
    {
        $citizen = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);

        Complaint::factory()->create([
            'user_id' => $citizen->id,
            'subject' => 'OPEN-MARKER',
        ]);
        Complaint::factory()->resolved()->create([
            'user_id' => $citizen->id,
            'subject' => 'RESOLVED-MARKER',
        ]);

        $this->actingAs($citizen)
            ->get(route('citizen.complaints.index', ['status' => ComplaintStatus::Resolved->value]))
            ->assertOk()
            ->assertSee('RESOLVED-MARKER')
            ->assertDontSee('OPEN-MARKER');
    }

    public function test_citizen_only_sees_their_own_complaints(): void
    {
        $citizen = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);
        $other = User::factory()->create(['role' => 'citizen', 'verification_status' => 'verified']);

        Complaint::factory()->create([
            'user_id' => $other->id,
            'subject' => 'SOMEONE-ELSE-MARKER',
        ]);

        $this->actingAs($citizen)
            ->get(route('citizen.complaints.index'))
            ->assertOk()
            ->assertDontSee('SOMEONE-ELSE-MARKER');
    }
}
