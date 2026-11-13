<?php

namespace Database\Factories;

use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Complaint>
 */
class ComplaintFactory extends Factory
{
    protected $model = Complaint::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'property_id' => null,
            'category' => $this->faker->randomElement(['Road', 'Drainage', 'Garbage', 'Streetlight', 'Noise']),
            'subject' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'status' => ComplaintStatus::Open->value,
            'admin_reply' => null,
            'attachment_path' => null,
            'resolved_at' => null,
            'resolved_by' => null,
            'rating' => null,
            'rated_at' => null,
            'closed_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => ComplaintStatus::Resolved->value,
            'admin_reply' => 'The issue has been resolved by the ward office.',
            'resolved_at' => now(),
            'closed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => ComplaintStatus::Rejected->value,
            'admin_reply' => 'This matter falls outside the municipal mandate.',
            'resolved_at' => now(),
            'closed_at' => now(),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => ComplaintStatus::InProgress->value,
        ]);
    }
}
