<?php

namespace App\Domain\Attachments;

use App\Models\TaskAttachment;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The attachment limits (DECIDED-004, API_SPEC.md §6.3, §13; CORE-008, ADR-027 §5): at most
 * `sn3.attachments.max_bytes` per file (25 MiB on Cloud) and `sn3.attachments.team_quota_bytes` of
 * stored attachments per team (2 GiB on Cloud). Self-hosted operators may change both; 0 means no
 * limit. Both are answered with 413 `payload_too_large` ("Attachment/body exceeds configured limit",
 * §1.3), before anything is stored.
 *
 * A team's usage is the size of every live attachment row of the team, in every project (trashed
 * tasks and projects included, because their files are still stored until the purge). Rows being
 * removed (`deleted_at`) no longer count.
 */
final class AttachmentQuota
{
    public static function maxBytes(): int
    {
        return max(0, config()->integer('sn3.attachments.max_bytes'));
    }

    public static function teamQuotaBytes(): int
    {
        return max(0, config()->integer('sn3.attachments.team_quota_bytes'));
    }

    public static function exceedsFileLimit(int $bytes): bool
    {
        $max = self::maxBytes();

        return $max > 0 && $bytes > $max;
    }

    public static function used(string $teamId): int
    {
        $sum = TaskAttachment::query()->where('team_id', $teamId)->live()->sum('size_bytes');

        return is_numeric($sum) ? (int) $sum : 0;
    }

    /**
     * @throws HttpException 413 when the file would take the team over its quota
     */
    public static function assertRoomFor(string $teamId, int $bytes): void
    {
        $quota = self::teamQuotaBytes();

        if ($quota > 0 && self::used($teamId) + $bytes > $quota) {
            throw new HttpException(413, 'The team attachment storage quota is exhausted.');
        }
    }
}
