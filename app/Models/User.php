<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'role',
        'verification_status',
        'password',
        'nid_number',
        'full_name',
        'date_of_birth',
        'father_name',
        'mother_name',
        'permanent_address',
        'present_address',
        'phone_number',
        'nid_front_image',
        'nid_back_image',
        'passport_photo',
        'verification_requested_at',
        'verified_at',
        'verified_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    public function isCitizen()
    {
        return $this->role === 'citizen';
    }

    public function getDisplayNameAttribute(): string
    {
        if (! empty($this->full_name)) {
            return $this->full_name;
        }

        if (! empty($this->name)) {
            return $this->name;
        }

        return 'User Name';
    }

    /*
    |--------------------------------------------------------------------------
    | Existing Citizen/NID Verification
    |--------------------------------------------------------------------------
    */

    public function isVerified()
    {
        return $this->verification_status === 'verified';
    }

    public function isNidBlocked()
    {
        if (empty($this->national_id)) {
            return false;
        }

        return \App\Models\FakeNid::isBlocked(
            $this->national_id
        );
    }

    public function getNidBlockedReason()
    {
        if (empty($this->national_id)) {
            return null;
        }

        return \App\Models\FakeNid::getBlockedReason(
            $this->national_id
        );
    }

    public function isNidAvailableForVerification()
    {
        if (empty($this->national_id)) {
            return false;
        }

        return \App\Models\FakeNid::isAvailableForVerification(
            $this->national_id
        );
    }

    public function getNidVerificationStatus()
    {
        if (empty($this->national_id)) {
            return [
                'status' => 'no_nid',
                'message' => 'No NID provided',
            ];
        }

        return \App\Models\FakeNid::getVerificationStatus(
            $this->national_id
        );
    }

    public function markNidAsVerified($verifiedByUserId)
    {
        if (empty($this->national_id)) {
            return false;
        }

        return \App\Models\FakeNid::markAsVerified(
            $this->national_id,
            $verifiedByUserId
        );
    }
}
