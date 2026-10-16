<?php

namespace App\Domain\Exports\Sections;

use App\Domain\Exports\TeamExportContext;
use App\Domain\Exports\TeamExportSection;

/**
 * `data/team.json`: the team itself, one record.
 */
final class TeamSection implements TeamExportSection
{
    public function name(): string
    {
        return 'team';
    }

    public function records(TeamExportContext $context): iterable
    {
        $team = $context->team;

        yield [
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
            'description' => $team->description,
            'owner_user_id' => $team->owner_user_id,
            'archived_at' => TeamExportContext::timestamp($team->archived_at),
            'created_at' => TeamExportContext::timestamp($team->created_at),
            'updated_at' => TeamExportContext::timestamp($team->updated_at),
        ];
    }
}
