<?php

namespace App\Domain\Notifications;

use App\Models\InboxNotification;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Renders notifications the recipient may see (rows from Inbox, never others) from the live rows, in
 * one query per kind of row (API_SPEC.md §9 representation; CORE-009, ADR-029 §4). Nothing comes from a
 * cached copy: a renamed task shows its current title, and a notification whose subject cannot be read
 * any more is not rendered at all (a defence in depth: Inbox already left it out).
 *
 * Shape: `id`, `type`, `team` `{id, name}`, `project` `{id, key, name}` or null, `task`
 * `{id, key, title}` or null, `comment_id`, `actor` `{id, name}` or null (a deleted account), `fields`
 * (changed task fields of `task.updated`), `event_count`, `invitation` `{role, expires_at}` or null,
 * `read_at`, `created_at` (first event), `updated_at` (latest event).
 *
 * @phpstan-type NotificationView array{id: string, type: string, team: array{id: string, name: string}, project: array{id: string, key: string, name: string}|null, task: array{id: string, key: string, title: string}|null, comment_id: string|null, actor: array{id: string, name: string}|null, fields: list<string>, event_count: int, invitation: array{role: string, expires_at: string}|null, read_at: string|null, created_at: string, updated_at: string}
 */
final class InboxPresenter
{
    /** Task fields a `task.updated` notification may name (UpdateTask's activity field names). */
    public const TASK_FIELDS = ['assignee_user_id', 'description', 'due_date', 'label_ids', 'parent_task_id', 'priority', 'status_id', 'title'];

    /**
     * @param  iterable<int, InboxNotification>  $notifications
     * @return list<NotificationView>
     */
    public function present(iterable $notifications): array
    {
        $rows = Collection::make($notifications)->values();

        if ($rows->isEmpty()) {
            return [];
        }

        $teams = Team::query()->whereKey($rows->pluck('team_id')->unique()->values()->all())->get(['id', 'name'])->keyBy('id');
        $projects = Project::query()->whereKey($rows->pluck('project_id')->filter()->unique()->values()->all())->get(['id', 'team_id', 'name', 'project_key'])->keyBy('id');
        $tasks = Task::query()->whereKey($rows->pluck('task_id')->filter()->unique()->values()->all())->get(['id', 'project_id', 'task_number', 'title'])->keyBy('id');
        $actors = User::query()->whereKey($rows->pluck('actor_user_id')->filter()->unique()->values()->all())->get(['id', 'name'])->keyBy('id');
        $invitations = TeamInvitation::query()->whereKey($rows->pluck('invitation_id')->filter()->unique()->values()->all())->get(['id', 'invited_role', 'expires_at'])->keyBy('id');

        $presented = [];

        foreach ($rows as $notification) {
            $team = $teams->get($notification->team_id);
            $project = $notification->project_id === null ? null : $projects->get($notification->project_id);
            $task = $notification->task_id === null ? null : $tasks->get($notification->task_id);
            $invitation = $notification->invitation_id === null ? null : $invitations->get($notification->invitation_id);

            if (! $team instanceof Team
                || ($notification->project_id !== null && ! $project instanceof Project)
                || ($notification->task_id !== null && ! $task instanceof Task)
                || ($notification->invitation_id !== null && ! $invitation instanceof TeamInvitation)) {
                continue;
            }

            $actor = $notification->actor_user_id === null ? null : $actors->get($notification->actor_user_id);

            $presented[] = [
                'id' => $notification->id,
                'type' => $notification->type->value,
                'team' => ['id' => $team->id, 'name' => $team->name],
                'project' => $project instanceof Project ? ['id' => $project->id, 'key' => $project->project_key, 'name' => $project->name] : null,
                'task' => $task instanceof Task && $project instanceof Project ? ['id' => $task->id, 'key' => $task->key($project), 'title' => $task->title] : null,
                'comment_id' => $notification->comment_id,
                'actor' => $actor instanceof User ? ['id' => $actor->id, 'name' => $actor->name] : null,
                'fields' => $notification->type === NotificationType::TaskUpdated ? $notification->fields() : [],
                'event_count' => $notification->event_count,
                'invitation' => $invitation instanceof TeamInvitation ? [
                    'role' => $invitation->invited_role->value,
                    'expires_at' => $invitation->expires_at->utc()->toIso8601ZuluString(),
                ] : null,
                'read_at' => $notification->read_at?->utc()->toIso8601ZuluString(),
                'created_at' => $notification->created_at->utc()->toIso8601ZuluString(),
                'updated_at' => $notification->updated_at->utc()->toIso8601ZuluString(),
            ];
        }

        return $presented;
    }

    /**
     * @return NotificationView|null
     */
    public function one(InboxNotification $notification): ?array
    {
        return $this->present([$notification])[0] ?? null;
    }
}
