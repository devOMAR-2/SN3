<?php

namespace App\Domain\Exports\Sections;

use App\Domain\Exports\TeamExportContext;
use App\Domain\Exports\TeamExportSection;
use App\Models\ProjectMembership;

/**
 * `data/project_members.json`: explicit project memberships of the exported projects.
 */
final class ProjectMembersSection implements TeamExportSection
{
    public function name(): string
    {
        return 'project_members';
    }

    public function records(TeamExportContext $context): iterable
    {
        $memberships = ProjectMembership::query()
            ->where('team_id', $context->team->id)
            ->whereIn('project_id', $context->projectIds())
            ->lazyById(500);

        foreach ($memberships as $membership) {
            yield [
                'project_id' => $membership->project_id,
                'user_id' => $membership->user_id,
                'project_role' => $membership->project_role->value,
                'added_by' => $membership->added_by,
                'created_at' => TeamExportContext::timestamp($membership->created_at),
            ];
        }
    }
}
