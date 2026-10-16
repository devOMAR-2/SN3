<?php

namespace App\Http\Resources;

use App\Domain\Exports\TeamExportFormat;
use App\Domain\Exports\TeamExportStatus;
use App\Models\TeamExport;
use Illuminate\Support\Facades\URL;

/**
 * A team export as its requesting Owner sees it (API_SPEC.md §3, §18; ADR-024 §3).
 *
 * `status` is `pending`, `processing`, `ready`, `failed` or `expired` (a ready export past
 * `expires_at`, until it is pruned). `download_url` is set only while the export is ready: a signed
 * URL that expires with the export and works only for the requesting Owner's session.
 */
final class TeamExportResource
{
    /**
     * @return array{id: string, team_id: string, requested_by: string, status: string, format: string, format_version: int, size_bytes: int|null, sha256: string|null, download_url: string|null, created_at: string|null, completed_at: string|null, expires_at: string}
     */
    public static function make(TeamExport $export): array
    {
        $downloadable = $export->isDownloadable();
        $status = $export->status === TeamExportStatus::Ready && ! $downloadable ? 'expired' : $export->status->value;

        return [
            'id' => $export->id,
            'team_id' => $export->team_id,
            'requested_by' => $export->requested_by,
            'status' => $status,
            'format' => TeamExportFormat::NAME,
            'format_version' => $export->format_version,
            'size_bytes' => $downloadable ? $export->size_bytes : null,
            'sha256' => $downloadable ? $export->sha256 : null,
            'download_url' => $downloadable ? self::downloadUrl($export) : null,
            'created_at' => $export->created_at?->utc()->toIso8601ZuluString(),
            'completed_at' => $export->completed_at?->utc()->toIso8601ZuluString(),
            'expires_at' => $export->expires_at->utc()->toIso8601ZuluString(),
        ];
    }

    public static function downloadUrl(TeamExport $export): string
    {
        return URL::temporarySignedRoute(
            'api.teams.exports.download',
            $export->expires_at,
            ['team' => $export->team_id, 'export' => $export->id],
        );
    }
}
