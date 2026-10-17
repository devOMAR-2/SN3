<?php

namespace App\Domain\Trash\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A trashed team was purged for good, with all its projects (ADR-022 §5), after its delete
 * committed. Listeners delete what is not a row, such as stored attachment files (CORE-008, ADR-027 §5).
 */
final class TeamPurged
{
    use Dispatchable;

    public function __construct(public readonly string $teamId) {}
}
