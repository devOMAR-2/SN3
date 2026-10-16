<?php

namespace App\Domain\Exports\Sections;

use App\Domain\Exports\TeamExportContext;
use App\Domain\Exports\TeamExportSection;
use App\Models\Project;

/**
 * `data/projects.json`: the team's projects the requesting Owner can see (all of them, private
 * ones included; trashed ones are not part of any read path and are left out).
 */
final class ProjectsSection implements TeamExportSection
{
    public function name(): string
    {
        return 'projects';
    }

    public function records(TeamExportContext $context): iterable
    {
        $projects = Project::query()
            ->whereIn('id', $context->projectIds())
            ->lazyById(500);

        foreach ($projects as $project) {
            yield [
                'id' => $project->id,
                'name' => $project->name,
                'key' => $project->project_key,
                'description' => $project->description,
                'visibility' => $project->visibility->value,
                'sprints_enabled' => $project->sprints_enabled,
                'created_by' => $project->created_by,
                'archived_at' => TeamExportContext::timestamp($project->archived_at),
                'created_at' => TeamExportContext::timestamp($project->created_at),
                'updated_at' => TeamExportContext::timestamp($project->updated_at),
            ];
        }
    }
}
