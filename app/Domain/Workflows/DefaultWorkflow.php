<?php

namespace App\Domain\Workflows;

use App\Models\Project;
use App\Models\ProjectStatus;

/**
 * The ready-to-use workflow every new project starts with (FR-CUST-002, UC-001, API_SPEC.md §5):
 * To do (the default for new tasks), In progress and Done, in that order.
 *
 * Names are ordinary, editable status names, written in the creator's language
 * (DESIGN_SYSTEM.md §7.1 `task.status.*`); the category is the language-neutral identifier.
 */
final class DefaultWorkflow
{
    /** @var list<StatusCategory> */
    private const CATEGORIES = [StatusCategory::Todo, StatusCategory::InProgress, StatusCategory::Done];

    /**
     * Call inside the transaction that creates the project.
     */
    public static function seed(Project $project, string $locale): void
    {
        foreach (self::CATEGORIES as $position => $category) {
            $name = __("workflow.default_statuses.{$category->value}", locale: $locale);

            $status = new ProjectStatus;
            $status->forceFill([
                'project_id' => $project->id,
                'team_id' => $project->team_id,
                'name' => is_string($name) ? $name : $category->value,
                'category' => $category,
                'position' => $position,
                'is_default' => $category === StatusCategory::Todo,
            ])->save();
        }
    }
}
