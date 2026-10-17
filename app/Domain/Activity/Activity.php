<?php

namespace App\Domain\Activity;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

/**
 * Writes the append-only activity and audit log (FR-COL-002, FR-TASK-006, NFR-SEC-005; DATABASE.md
 * §5 `task_activities`; CORE-008, ADR-027 §3). Call it inside the writer's transaction, after the
 * change, so the entry commits or rolls back with it (ARCHITECTURE.md §7 "Status transitions and
 * activity records are one transaction").
 *
 * Three levels, each readable only by those who may see it (ActivityFeed):
 *
 * - **task** entries (`task_id`, `project_id`): task, checklist, comment and attachment changes;
 * - **project** entries (`project_id` only): project creation, visibility and membership changes;
 * - **team** entries (neither): team role changes, removals and ownership transfers.
 *
 * `changes` is a bounded allowlist (DATABASE.md §5): every action declares the keys it may carry, and
 * values are IDs, enum values, dates, counts, booleans or field names, never titles, descriptions,
 * comment bodies, file names or other free text, which may be private. A key or value outside the
 * allowlist is a programming error (LogicException), never silently stored.
 */
final class Activity
{
    public const SOURCE_USER = 'user';

    public const SOURCE_SYSTEM = 'system';

    /**
     * Every action and the `changes` keys it may carry.
     *
     * @var array<string, list<string>>
     */
    public const ACTIONS = [
        // Tasks (API_SPEC.md §6.1; DATABASE.md §11 "insert task and activity atomically").
        'task.created' => ['status_id', 'assignee_user_id', 'parent_task_id', 'priority', 'due_date'],
        'task.updated' => ['fields', 'status_id', 'assignee_user_id', 'parent_task_id', 'priority', 'due_date'],
        // A board move (CORE-007, ADR-028 §2): `status_id` as a {from, to} pair; equal for a reorder.
        'task.moved' => ['status_id'],
        'task.trashed' => [],
        'task.restored' => [],
        // An assignee who lost write access is unassigned by the change that took it (ADR-023 §5).
        'task.assignee_released' => ['from', 'reason'],
        'task.checklist_item_created' => ['item_id'],
        'task.checklist_item_updated' => ['item_id', 'fields', 'checked'],
        'task.checklist_item_deleted' => ['item_id'],
        // Comments (API_SPEC.md §6.2): a deleted comment leaves only this tombstone, no content.
        'comment.created' => ['comment_id', 'mentions'],
        'comment.edited' => ['comment_id', 'mentions'],
        'comment.deleted' => ['comment_id', 'moderated'],
        // Attachments (API_SPEC.md §6.3): no file name, which may be private.
        'attachment.added' => ['attachment_id', 'mime_type', 'size_bytes'],
        'attachment.removed' => ['attachment_id', 'moderated'],
        // Projects (NFR-SEC-005: membership and visibility changes).
        'project.created' => ['visibility'],
        'project.visibility_changed' => ['from', 'to'],
        'project.member_added' => ['user_id', 'role'],
        'project.member_role_changed' => ['user_id', 'from', 'to'],
        'project.member_removed' => ['user_id', 'role'],
        // Teams (NFR-SEC-005, ARCHITECTURE.md §11.2: role changes, removals, ownership transfer).
        'team.member_role_changed' => ['user_id', 'from', 'to'],
        'team.member_removed' => ['user_id'],
        'team.ownership_transferred' => ['from_user_id', 'to_user_id'],
        // Invitations (CORE-002, ADR-025; ADR-027 §3): IDs, type and role only, never the address or
        // the token. A membership created by accepting one is `team.member_added`, by the joining user.
        'team.member_added' => ['user_id', 'role', 'invitation_id'],
        'team.invitation_created' => ['invitation_id', 'type', 'role'],
        'team.invitation_revoked' => ['invitation_id'],
        'team.invitations_revoked' => ['issuer_id', 'reason', 'count'],
    ];

    /** Longest string value: an ID, enum value, date or field name. */
    private const MAX_STRING = 64;

    /**
     * @param  array<string, mixed>  $changes
     */
    public static function task(Task $task, ?User $actor, string $action, array $changes = [], string $source = self::SOURCE_USER): TaskActivity
    {
        return self::write($task->team_id, $task->project_id, $task->id, $actor, $action, $changes, $source);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public static function project(Project $project, ?User $actor, string $action, array $changes = [], string $source = self::SOURCE_USER): TaskActivity
    {
        return self::write($project->team_id, $project->id, null, $actor, $action, $changes, $source);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public static function team(string $teamId, ?User $actor, string $action, array $changes = [], string $source = self::SOURCE_USER): TaskActivity
    {
        return self::write($teamId, null, null, $actor, $action, $changes, $source);
    }

    /**
     * Set-based entries for many tasks at once, inside the caller's transaction: one row per task the
     * `$tasks` SQL selects (it must select `id`, `project_id`, `team_id` and every column named in
     * `$columns`). `changes` is `$constants` plus, per key of `$columns`, that column's value. Used by
     * TaskAssignments, whose releases are single UPDATE statements.
     *
     * @param  array<int, mixed>  $bindings  for `$tasks`
     * @param  array<string, mixed>  $constants
     * @param  array<string, string>  $columns  change key => column of `$tasks`
     * @return int the entries written
     */
    public static function forTasksSql(string $tasks, array $bindings, string $action, array $constants, array $columns, ?string $actorId, string $source = self::SOURCE_SYSTEM): int
    {
        self::assertAction($action);
        self::assertChanges($action, $constants);

        $pairs = [];

        foreach ($columns as $key => $column) {
            if (! in_array($key, self::ACTIONS[$action], true) || preg_match('/^[a-z_]+$/', $column) !== 1) {
                throw new LogicException("Activity [{$action}] may not record [{$key}].");
            }

            $pairs[] = "'{$key}', t.{$column}";
        }

        $changes = $pairs === [] ? '?::jsonb' : '(?::jsonb || jsonb_build_object('.implode(', ', $pairs).'))';

        return DB::affectingStatement(
            'INSERT INTO task_activities (id, team_id, project_id, task_id, actor_user_id, action, changes, source, created_at) '
            ."SELECT sn3_uuid_v7(), t.team_id, t.project_id, t.id, ?::uuid, ?, {$changes}, ?, now() FROM ({$tasks}) AS t",
            [$actorId, $action, json_encode((object) $constants, JSON_THROW_ON_ERROR), $source, ...$bindings],
        );
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private static function write(string $teamId, ?string $projectId, ?string $taskId, ?User $actor, string $action, array $changes, string $source): TaskActivity
    {
        self::assertAction($action);
        self::assertChanges($action, $changes);

        if (! in_array($source, [self::SOURCE_USER, self::SOURCE_SYSTEM], true)) {
            throw new LogicException("Unknown activity source [{$source}].");
        }

        $entry = new TaskActivity;
        $entry->forceFill([
            'team_id' => $teamId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            // Always a JSON object, also when empty (the CHECK constraint requires one).
            'changes' => $changes === [] ? new stdClass : $changes,
            'source' => $source,
            'created_at' => now(),
        ])->save();

        return $entry;
    }

    private static function assertAction(string $action): void
    {
        if (! array_key_exists($action, self::ACTIONS)) {
            throw new LogicException("Unknown activity action [{$action}].");
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private static function assertChanges(string $action, array $changes): void
    {
        foreach ($changes as $key => $value) {
            if (! in_array($key, self::ACTIONS[$action], true)) {
                throw new LogicException("Activity [{$action}] may not record [{$key}].");
            }

            self::assertValue($action, $key, $value, nested: false);
        }
    }

    private static function assertValue(string $action, string $key, mixed $value, bool $nested): void
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }

        if (is_string($value)) {
            if (mb_strlen($value) > self::MAX_STRING) {
                throw new LogicException("Activity [{$action}] value of [{$key}] is too long.");
            }

            return;
        }

        if (! $nested && is_array($value)) {
            // A list of field names, or a {from, to} pair.
            if (array_is_list($value) || array_diff(array_keys($value), ['from', 'to']) === []) {
                foreach ($value as $item) {
                    self::assertValue($action, $key, $item, nested: true);
                }

                return;
            }
        }

        throw new LogicException("Activity [{$action}] value of [{$key}] is not allowed.");
    }
}
