<?php

namespace App\Models;

use App\Enums\ComplaintStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'property_id',
        'category',
        'subject',
        'description',
        'status',
        'admin_reply',
        'attachment_path',
        'resolved_at',
        'resolved_by',
        'rating',
        'rated_at',
        'closed_at',
    ];

    protected $casts = [
        'status' => ComplaintStatus::class,
        'resolved_at' => 'datetime',
        'rated_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * The status timeline, oldest first.
     */
    public function updates()
    {
        return $this->hasMany(ComplaintUpdate::class)->orderBy('created_at');
    }

    /**
     * Record a status transition on the timeline.
     */
    public function logStatusChange(?ComplaintStatus $from, ComplaintStatus $to, ?string $note = null, ?int $changedBy = null): ComplaintUpdate
    {
        return $this->updates()->create([
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'note' => $note,
            'changed_by' => $changedBy,
        ]);
    }

    public function isClosed(): bool
    {
        return $this->status?->isClosed() ?? false;
    }
}
