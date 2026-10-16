<?php

namespace App\Domain\Exports\Sections;

use App\Domain\Exports\TeamExportContext;
use App\Domain\Exports\TeamExportSection;
use App\Models\Label;

/**
 * `data/labels.json`: the labels of the exported projects (CORE-005, ADR-023 §6).
 */
final class LabelsSection implements TeamExportSection
{
    public function name(): string
    {
        return 'labels';
    }

    public function records(TeamExportContext $context): iterable
    {
        $labels = Label::query()
            ->where('team_id', $context->team->id)
            ->whereIn('project_id', $context->projectIds())
            ->lazyById(500);

        foreach ($labels as $label) {
            yield [
                'id' => $label->id,
                'project_id' => $label->project_id,
                'name' => $label->name,
                'color' => $label->color,
                'created_at' => TeamExportContext::timestamp($label->created_at),
                'updated_at' => TeamExportContext::timestamp($label->updated_at),
            ];
        }
    }
}
