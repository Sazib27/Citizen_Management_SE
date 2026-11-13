<?php

namespace App\Models;

use App\Enums\ComplaintStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in a complaint's status timeline.
 *
 * The complaint row itself only ever holds the *current* status. This
 * table records how it got there, which is what lets a citizen track
 * the progress of a grievance rather than just see its end state.
 */
class ComplaintUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'complaint_id',
        'changed_by',
        'from_status',
        'to_status',
        'note',
    ];

    protected $casts = [
        'from_status' => ComplaintStatus::class,
        'to_status' => ComplaintStatus::class,
    ];

    public function complaint()
    {
        return $this->belongsTo(Complaint::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
