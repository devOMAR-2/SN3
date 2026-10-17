<?php

namespace App\Domain\Tasks;

use App\Domain\Projects\ProjectRole;
use App\Domain\Projects\ProjectVisibility;
use App\Domain\Teams\TeamRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * Who may be a task's single assignee (DECIDED-006, API_SPEC.md §6.1, DATABASE.md §19), and what
 * happens to assignments when that stops being true (ADR-023 §5).
 *
 * **Eligible:** an active (not disabled) member of the project's team who may write tasks in the
 * project, which is exactly ProjectPolicy::contribute: the Team Owner; a Team Admin or Member on a
 * team-visible project; a Team Admin or Member with an explicit project membership on a private
 * project. A Team Viewer never is, whatever their project role. Everyone else is answered like an
 * unknown user (422 `exists`), so the field is no probe for accounts or private memberships.
 *
 * **Losing eligibility:** the specs do not say what happens to an assignment then, so it is cleared
 * in the same transaction as the change that took the right away, and the task's `version` moves
 * (its representation changed). The callers are the membership and visibility writers:
 *
 * - RemoveMember (team removal or leaving): `releaseUser()` before the membership row goes; the
 *   database refuses to delete a team membership that tasks are still assigned to;
 * - ChangeMemberRole (to Viewer), TransferOwnership (the former Owner loses implicit private access),
 *   RemoveProjectMember and UpdateProject (made private): `releaseIneligible()`.
 *
 * These writers hold the team row lock (and the project lock where there is one), the same first
 * lock every task writer takes, so an assignment cannot slip in between the check and the change.
 * Trashed tasks and tasks of archived or trashed projects are included: a task restored later never
 * brings back an assignee who lost access meanwhile.
 */
final class TaskAssignments
{
    public static function eligible(string $projectId, string $userId): bool
    {
        $row = DB::selectOne('SELECT '.self::predicate('?::uuid', '?::uuid').' AS eligible', [$projectId, $userId]);

        return $row instanceof stdClass && ($row->eligible ?? false) === true;
    }

    /**
     * Every user who may be the project's assignee right now, by name (the assignee picker of the task
     * detail page, CORE-006, ADR-026 §5). The same SQL rule as eligible(), so the picker never offers
     * someone the API would refuse.
     *
     * At most `$limit` users, by name.
     *
     * @return list<array{user_id: string, name: string}>
     */
    public static function eligibleUsers(string $projectId, int $limit): array
    {
        $rows = DB::select(
            'SELECT u.id AS user_id, u.name FROM team_memberships AS tm JOIN users AS u ON u.id = tm.user_id '
            .'WHERE tm.team_id = (SELECT p.team_id FROM projects AS p WHERE p.id = ?::uuid) AND '.self::predicate('?::uuid', 'tm.user_id')
            .' ORDER BY u.name, u.id LIMIT ?',
            [$projectId, $projectId, max(1, $limit)],
        );

        $users = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass && is_string($row->user_id ?? null) && is_string($row->name ?? null)) {
                $users[] = ['user_id' => $row->user_id, 'name' => $row->name];
            }
        }

        return $users;
    }

    /**
     * Unassigns a user from every task of the team: they are leaving it.
     *
     * @return int how many tasks were unassigned
     */
    public static function releaseUser(string $teamId, string $userId, string $reason): int
    {
        $released = DB::update(
            'UPDATE tasks SET assignee_user_id = NULL, version = version + 1, updated_at = now() WHERE team_id = ? AND assignee_user_id = ?',
            [$teamId, $userId],
        );

        self::log($released, $teamId, $userId, null, $reason);

        return $released;
    }

    /**
     * Unassigns every assignee of the team's tasks (optionally only one user's, or only in one
     * project) who is no longer eligible.
     *
     * @return int how many tasks were unassigned
     */
    public static function releaseIneligible(string $teamId, ?string $userId, ?string $projectId, string $reason): int
    {
        $sql = 'UPDATE tasks AS t SET assignee_user_id = NULL, version = t.version + 1, updated_at = now() '
            .'WHERE t.team_id = ? AND t.assignee_user_id IS NOT NULL';
        $bindings = [$teamId];

        if ($userId !== null) {
            $sql .= ' AND t.assignee_user_id = ?';
            $bindings[] = $userId;
        }

        if ($projectId !== null) {
            $sql .= ' AND t.project_id = ?';
            $bindings[] = $projectId;
        }

        $released = DB::update($sql.' AND NOT '.self::predicate('t.project_id', 't.assignee_user_id'), $bindings);

        self::log($released, $teamId, $userId, $projectId, $reason);

        return $released;
    }

    /**
     * The eligibility rule as one SQL expression, shared by the check and the release so the two can
     * never disagree. `$project` and `$user` are SQL expressions (columns or `?::uuid` placeholders,
     * bound in that order).
     */
    private static function predicate(string $project, string $user): string
    {
        $owner = TeamRole::Owner->value;
        $writers = "'".TeamRole::Admin->value."', '".TeamRole::Member->value."'";
        $team = ProjectVisibility::Team->value;
        $roles = "'".ProjectRole::Manager->value."', '".ProjectRole::Contributor->value."'";

        return 'EXISTS (SELECT 1 FROM projects AS ep JOIN team_memberships AS etm ON etm.team_id = ep.team_id JOIN users AS eu ON eu.id = etm.user_id '
            ."WHERE ep.id = {$project} AND etm.user_id = {$user} AND eu.disabled_at IS NULL "
            ."AND (etm.role = '{$owner}' OR (etm.role IN ({$writers}) AND (ep.visibility = '{$team}' "
            ."OR EXISTS (SELECT 1 FROM project_memberships AS epm WHERE epm.project_id = ep.id AND epm.user_id = etm.user_id AND epm.project_role IN ({$roles}))))))";
    }

    private static function log(int $released, string $teamId, ?string $userId, ?string $projectId, string $reason): void
    {
        if ($released === 0) {
            return;
        }

        Log::info('task.assignments_released', array_filter([
            'team_id' => $teamId,
            'project_id' => $projectId,
            'user_id' => $userId,
            'reason' => $reason,
            'tasks' => $released,
        ], fn (mixed $value): bool => $value !== null));
    }
}
