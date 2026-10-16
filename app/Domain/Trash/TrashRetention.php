<?php

namespace App\Domain\Trash;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The 30-day trash window for teams and projects (DECIDED-004, DECIDED-011, ADR-022).
 *
 * A row trashed at `deleted_at` can be recovered while `deleted_at > now - 30 days` and is purged
 * once `deleted_at <= now - 30 days`. The two predicates are exact complements, so a row is never
 * both recoverable and purgeable, and the outcome does not depend on when the purge last ran.
 * The window is a product decision, not an operational limit, so it is not configurable.
 */
final class TrashRetention
{
    public const DAYS = 30;

    /** Rows trashed at or before this instant are past recovery and due for the purge. */
    public static function cutoff(?CarbonInterface $now = null): CarbonImmutable
    {
        return CarbonImmutable::instance($now ?? now())->subDays(self::DAYS);
    }

    public static function isRecoverable(?CarbonInterface $deletedAt, ?CarbonInterface $now = null): bool
    {
        return $deletedAt !== null && $deletedAt->greaterThan(self::cutoff($now));
    }
}
