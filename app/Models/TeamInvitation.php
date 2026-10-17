<?php

namespace App\Models;

use App\Domain\Invitations\InvitationStatus;
use App\Domain\Teams\TeamRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A team invitation (DATABASE.md §3, API_SPEC.md §3.1; CORE-002, ADR-025; DECIDED-007).
 *
 * Either email-bound (`email` set, one use) or a share link (`email` NULL, up to 20 uses). Only the
 * SHA-256 of its token is stored; `token_hash` is never selected into the model by the invitation
 * code and is hidden from serialisation.
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $email
 * @property TeamRole $invited_role
 * @property string $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property int $max_uses
 * @property int $use_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User $inviter
 */
class TeamInvitation extends Model
{
    use HasUuids;

    /** DECIDED-007: every invitation expires 7 days after it is created. */
    public const TTL_DAYS = 7;

    /** DECIDED-007: an email-bound invitation is redeemed once, a share link up to 20 times. */
    public const EMAIL_MAX_USES = 1;

    public const LINK_MAX_USES = 20;

    /** The columns the invitation code reads; `token_hash` is deliberately not one of them. */
    public const COLUMNS = [
        'id', 'team_id', 'email', 'invited_role', 'invited_by', 'expires_at', 'accepted_at',
        'revoked_at', 'max_uses', 'use_count', 'created_at', 'updated_at',
    ];

    /**
     * Written by the invitation actions only.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invited_role' => TeamRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'max_uses' => 'integer',
            'use_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * The invitation whose token hashes to `$hashHex` (lower-case hex SHA-256), without its hash.
     *
     * @return Builder<TeamInvitation>
     */
    public static function whereTokenHash(string $hashHex): Builder
    {
        return self::query()->select(self::COLUMNS)->whereRaw("token_hash = decode(?, 'hex')", [$hashHex]);
    }

    public function isEmailBound(): bool
    {
        return $this->email !== null;
    }

    public function status(): InvitationStatus
    {
        return match (true) {
            $this->revoked_at !== null => InvitationStatus::Revoked,
            $this->accepted_at !== null => InvitationStatus::Accepted,
            $this->expires_at->isPast() => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    /** Pending: neither revoked nor used up, and not past its expiry. */
    public function isRedeemable(): bool
    {
        return $this->status() === InvitationStatus::Pending;
    }
}
