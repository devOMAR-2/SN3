<?php

namespace App\Models;

use App\Domain\Exports\TeamExportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An asynchronous team export (DATABASE.md §20, API_SPEC.md §3, §13, §18; CORE-012, ADR-024).
 *
 * Created by RequestTeamExport, built by the BuildTeamExport job, and deleted with its file by
 * `exports:prune` once `expires_at` has passed. Only the requesting Team Owner reads or downloads it.
 *
 * @property string $id
 * @property string $team_id
 * @property string $requested_by
 * @property TeamExportStatus $status
 * @property int $format_version
 * @property string|null $storage_key
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property Carbon|null $completed_at
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
class TeamExport extends Model
{
    use HasUuids;

    /** Hours a request may wait, and a finished export stays downloadable (API_SPEC.md §13, §18). */
    public const TTL_HOURS = 24;

    /**
     * Written by the export actions and job only.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TeamExportStatus::class,
            'format_version' => 'integer',
            'size_bytes' => 'integer',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Ready and not yet expired: the only state with a download. */
    public function isDownloadable(): bool
    {
        return $this->status === TeamExportStatus::Ready && ! $this->isExpired() && $this->storage_key !== null;
    }
}
