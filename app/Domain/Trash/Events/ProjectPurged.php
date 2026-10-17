<?php

namespace App\Domain\Trash\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A trashed project was purged for good (ADR-022 §5), after its delete committed. Its rows are gone;
 * listeners delete what is not a row, such as stored attachment files (CORE-008, ADR-027 §5).
 */
final class ProjectPurged
{
    use Dispatchable;

    public function __construct(
        public readonly string $teamId,
        public readonly string $projectId,
    ) {}
}
