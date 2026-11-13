<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A digitally verifiable citizen verification certificate.
 *
 * Previously the "certificate" was rendered on demand from the user's
 * row and its number was derived from the user's id, which meant it was
 * not a real artefact: it could not be revoked, had no issuance
 * record, and anyone who knew a user id could guess the number.
 *
 * Persisting it makes the certificate a first-class object with its own
 * lifecycle (valid / expired / revoked) and a public verification
 * endpoint that looks it up by number.
 */
class Certificate extends Model
{
    use HasFactory;

    public const STATUS_VALID = 'valid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'user_id',
        'certificate_number',
        'status',
        'issued_at',
        'expires_at',
        'revoked_at',
        'revocation_reason',
        'holder_name',
        'nid_number',
        'date_of_birth',
        'issued_by',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'date_of_birth' => 'date',
    ];

    /**
     * Generate a random, unguessable certificate number.
     *
     * Format: VCN-XXXXXXXX (8 uppercase hex characters).
     */
    public static function generateNumber(): string
    {
        return 'VCN-'.strtoupper(Str::random(8));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Whether the certificate is currently valid.
     */
    public function isValid(): bool
    {
        if ($this->status !== self::STATUS_VALID) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * The status actually applying right now, accounting for expiry.
     */
    public function effectiveStatus(): string
    {
        if ($this->status === self::STATUS_REVOKED) {
            return self::STATUS_REVOKED;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }

    /**
     * Issue (or re-issue) a certificate for a citizen.
     */
    public static function issueFor(User $user, ?int $issuedBy = null, ?\DateTimeInterface $expiresAt = null): self
    {
        // Revoke any previous certificate so only one is authoritative.
        static::where('user_id', $user->id)
            ->where('status', self::STATUS_VALID)
            ->update([
                'status' => self::STATUS_REVOKED,
                'revoked_at' => now(),
                'revocation_reason' => 'Superseded by a newly issued certificate.',
            ]);

        return static::create([
            'user_id' => $user->id,
            'certificate_number' => static::generateNumber(),
            'status' => self::STATUS_VALID,
            'issued_at' => now(),
            'expires_at' => $expiresAt,
            'holder_name' => $user->full_name ?: $user->name,
            'nid_number' => $user->nid_number ?: '',
            'date_of_birth' => $user->date_of_birth,
            'issued_by' => $issuedBy,
        ]);
    }
}
