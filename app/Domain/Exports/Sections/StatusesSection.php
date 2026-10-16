<?php

namespace App\Domain\Exports\Sections;

use App\Domain\Exports\TeamExportContext;
use App\Domain\Exports\TeamExportSection;
use App\Models\ProjectStatus;

/**
 * `data/statuses.json`: workflow statuses of the exported projects (CORE-004). `position` is the
 * 0-based board order.
 */
final class StatusesSection implements TeamExportSection
{
    public function name(): string
    {
        return 'statuses';
    }

    public function records(TeamExportContext $context): iterable
    {
        $statuses = ProjectStatus::query()
            ->where('team_id', $context->team->id)
            ->whereIn('project_id', $context->projectIds())
            ->lazyById(500);

        foreach ($statuses as $status) {
            yield [
                'id' => $status->id,
                'project_id' => $status->project_id,
                'name' => $status->name,
                'category' => $status->category->value,
                'position' => $status->position,
                'color' => $status->color,
                'is_default' => $status->is_default,
                'created_at' => TeamExportContext::timestamp($status->created_at),
                'updated_at' => TeamExportContext::timestamp($status->updated_at),
            ];
        }
    }
}
