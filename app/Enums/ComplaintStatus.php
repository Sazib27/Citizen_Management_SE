<?php

namespace App\Enums;

/**
 * The lifecycle of a complaint.
 *
 * Previously the status was a free-text string duplicated across the
 * model, both controllers and four Blade templates. A backed enum makes
 * the set of valid states explicit and gives the workflow a single
 * source of truth.
 */
enum ComplaintStatus: string
{
    /** Submitted by the citizen, not yet reviewed. */
    case Open = 'open';

    /** An admin is actively working on the grievance. */
    case InProgress = 'in_progress';

    /** The admin supplied a resolution the citizen can read. */
    case Resolved = 'resolved';

    /** The admin declined the grievance with a stated reason. */
    case Rejected = 'rejected';

    /**
     * The statuses an admin may move a complaint to from a given state.
     *
     * A complaint that has been closed (resolved or rejected) may be
     * reopened, and anything still open may be rejected.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::Resolved, self::Rejected],
            self::InProgress => [self::Resolved, self::Rejected],
            self::Resolved, self::Rejected => [self::Open, self::InProgress],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isClosed(): bool
    {
        return $this === self::Resolved || $this === self::Rejected;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In Progress',
            self::Resolved => 'Resolved',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * The Tailwind classes used to render the status badge.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Open => 'bg-yellow-100 text-yellow-800',
            self::InProgress => 'bg-blue-100 text-blue-800',
            self::Resolved => 'bg-green-100 text-green-800',
            self::Rejected => 'bg-red-100 text-red-800',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
