<?php

namespace App\Domain\Workflows;

/**
 * The language-neutral meaning of a project status (API_SPEC.md §5, DATABASE.md §5
 * `project_statuses.category`). Names are free text; categories are what integrations and later
 * features rely on, e.g. GitHub "closed" maps to a `done` status (DECIDED-005) and automation
 * needs a status of the matching category (API_SPEC.md §10).
 *
 * Every project keeps at least one `todo` and one `done` status (API_SPEC.md §5).
 */
enum StatusCategory: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';

    /**
     * Categories a project must always have at least one status in.
     *
     * @return list<self>
     */
    public static function required(): array
    {
        return [self::Todo, self::Done];
    }
}
