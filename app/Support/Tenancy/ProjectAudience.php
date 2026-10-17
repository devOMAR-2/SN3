<?php

namespace App\Support\Tenancy;

use App\Domain\Projects\ProjectVisibility;
use App\Domain\Teams\TeamRole;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Which of a set of users can see a project right now (ProjectPolicy::view, AccessibleProjects), in
 * one set-based query: an enabled member of the project's team, and for a private project the Team
 * Owner or an explicit project member (DECIDED-003). Used where the server picks people rather than
 * a person asking for something: mention candidates (CORE-008) and notification recipients (CORE-009,
 * which re-checks at send time and again at read time).
 *
 * It does not look at the team's or project's trash: callers resolve the live project first.
 */
final class ProjectAudience
{
    /**
     * The given users who can see the project, sorted and distinct.
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public static function filter(string $projectId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = DB::select(
            'SELECT DISTINCT u.id FROM users AS u '
            .'JOIN projects AS p ON p.id = ?::uuid '
            .'JOIN team_memberships AS tm ON tm.team_id = p.team_id AND tm.user_id = u.id '
            .'WHERE u.id = ANY(?::uuid[]) AND u.disabled_at IS NULL '
            .'AND (p.visibility = ? OR tm.role = ? OR EXISTS (SELECT 1 FROM project_memberships AS pm WHERE pm.project_id = p.id AND pm.user_id = u.id)) '
            .'ORDER BY u.id',
            [$projectId, '{'.implode(',', $userIds).'}', ProjectVisibility::Team->value, TeamRole::Owner->value],
        );

        $ids = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass && is_string($row->id ?? null)) {
                $ids[] = $row->id;
            }
        }

        return $ids;
    }
}
