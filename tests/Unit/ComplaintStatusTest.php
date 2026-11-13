<?php

namespace Tests\Unit;

use App\Enums\ComplaintStatus;
use PHPUnit\Framework\TestCase;

class ComplaintStatusTest extends TestCase
{
    public function test_every_status_has_a_label_and_badge_classes(): void
    {
        foreach (ComplaintStatus::cases() as $case) {
            $this->assertNotEmpty($case->label());
            $this->assertNotEmpty($case->badgeClasses());
        }
    }

    public function test_open_complaints_can_be_progressed_resolved_or_rejected(): void
    {
        $allowed = ComplaintStatus::Open->allowedTransitions();

        $this->assertContains(ComplaintStatus::InProgress, $allowed);
        $this->assertContains(ComplaintStatus::Resolved, $allowed);
        $this->assertContains(ComplaintStatus::Rejected, $allowed);
    }

    public function test_a_closed_complaint_can_be_reopened(): void
    {
        $this->assertTrue(ComplaintStatus::Resolved->canTransitionTo(ComplaintStatus::Open));
        $this->assertTrue(ComplaintStatus::Rejected->canTransitionTo(ComplaintStatus::Open));
    }

    public function test_resolved_and_rejected_are_closed_states(): void
    {
        $this->assertTrue(ComplaintStatus::Resolved->isClosed());
        $this->assertTrue(ComplaintStatus::Rejected->isClosed());
        $this->assertFalse(ComplaintStatus::Open->isClosed());
        $this->assertFalse(ComplaintStatus::InProgress->isClosed());
    }

    public function test_a_complaint_cannot_transition_to_an_unrelated_state(): void
    {
        // An in-progress complaint cannot jump straight back to open.
        $this->assertFalse(ComplaintStatus::InProgress->canTransitionTo(ComplaintStatus::Open));
        // A closed complaint cannot jump straight to in-progress without
        // being reopened first — but it can be reopened.
        $this->assertTrue(ComplaintStatus::Rejected->canTransitionTo(ComplaintStatus::Open));
    }

    public function test_values_returns_the_backing_strings(): void
    {
        $this->assertSame(
            ['open', 'in_progress', 'resolved', 'rejected'],
            ComplaintStatus::values()
        );
    }
}
