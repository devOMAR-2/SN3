<?php

namespace App\Domain\Workflows;

use App\Models\ProjectStatus;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Workflow invariants shared by the status actions (API_SPEC.md §5, DATABASE.md §5, ADR-021).
 */
final class StatusRules
{
    /** API_SPEC.md §5: status names are 1–50 characters. */
    public const NAME_MAX = 50;

    /** Hex RGB, upper-cased at the application boundary (the database checks the same format). */
    public const COLOR_PATTERN = '/^#[0-9A-F]{6}$/';

    /**
     * Upper bound per project (ADR-021 §4): keeps the board, the full reorder list and the status
     * list (one page at the maximum `limit`) bounded. The spec sets no number.
     */
    public const MAX_STATUSES = 50;

    private const NAME_INDEX = 'project_statuses_project_id_lower_name_unique';

    /**
     * Every project keeps at least one `todo` and one `done` status (API_SPEC.md §5).
     *
     * @param  Collection<int, ProjectStatus>  $remaining  the project's statuses after the change
     */
    public static function assertRequiredCategories(Collection $remaining): void
    {
        foreach (StatusCategory::required() as $category) {
            if (! $remaining->contains(fn (ProjectStatus $status): bool => $status->category === $category)) {
                throw new DomainConflictException("A project needs at least one {$category->value} status.");
            }
        }
    }

    /**
     * Maps a duplicate name (case-insensitive, per project) to 409 and rethrows anything else.
     */
    public static function duplicateName(UniqueConstraintViolationException $e): DomainConflictException
    {
        if (! str_contains($e->getMessage(), self::NAME_INDEX)) {
            throw $e;
        }

        return new DomainConflictException('A status with this name already exists in the project.', previous: $e);
    }
}
