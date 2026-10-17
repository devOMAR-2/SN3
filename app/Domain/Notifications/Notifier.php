<?php

namespace App\Domain\Notifications;

use App\Domain\Comments\CommentMentions;
use App\Domain\Comments\Events\CommentAdded;
use App\Domain\Comments\Events\UsersMentioned;
use App\Domain\Invitations\Events\InvitationSent;
use App\Domain\Tasks\Events\TaskChanged;
use App\Jobs\SendNotificationEmail;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Tenancy\ProjectAudience;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Turns domain events into notifications (API_SPEC.md §9; FR-COL-003, FR-COL-004; CORE-009, ADR-029 §2).
 * Runs in a queued listener, after the change committed, with IDs only.
 *
 * **Send-time check.** Each delivery runs in one transaction that first takes a SHARE lock on the team
 * row. Every membership, role, ownership, visibility and trash writer takes the team row lock first
 * (ADR-018 §4), so nobody can lose access between the check and the insert. Then it re-resolves the
 * live task, project and team (anything trashed or gone: nothing is sent) and keeps only the recipients
 * who can see the project now (ProjectAudience). The inbox checks again at read time (Inbox), so a
 * recipient who loses access later stops seeing it at once.
 *
 * **Who:** never the actor.
 * - `task.assigned`: the new assignee, when someone else assigned them (on create or edit);
 * - `task.updated`: the task's watchers, for a real edit (field names only), except the new assignee,
 *   who has `task.assigned` for it;
 * - `task.commented`: the task's watchers, for a new comment, except those it mentions;
 * - `comment.mentioned`: each newly mentioned user still mentioned in the live comment;
 * - `team.invitation`: the verified, enabled account an email invitation is addressed to, unless it
 *   already belongs to the team.
 *
 * **Collapsing (batch/dedupe):** the task types are upserted on the partial unique index
 * `notifications_collapse_unique`: while the recipient has an unread one for the task, a new event
 * raises its `event_count`, moves its time and actor, and merges the field names. A mention is one per
 * comment and user, an invitation one per invitation (ON CONFLICT DO NOTHING).
 *
 * **Preferences:** users with both email and in-app notifications off get nothing recorded. With email
 * on, the notification's email is scheduled once per open window (SendNotificationEmail).
 */
final class Notifier
{
    public function taskChanged(TaskChanged $event): void
    {
        $this->withinTeam($event->teamId, function () use ($event): void {
            $task = self::liveTask($event->taskId, $event->projectId);

            if ($task === null) {
                return;
            }

            $assignee = $event->assignedUserId;

            if ($assignee !== null && $task->assignee_user_id === $assignee) {
                $this->deliver(NotificationType::TaskAssigned, $task, $event->actorId, self::recipients([$assignee], $event->actorId));
            }

            if ($event->fields !== []) {
                $watchers = self::recipients(TaskWatchers::of($task), $event->actorId, [$assignee]);
                $this->deliver(NotificationType::TaskUpdated, $task, $event->actorId, $watchers, ['fields' => self::fields($event->fields)]);
            }
        });
    }

    public function commentAdded(CommentAdded $event): void
    {
        $this->withinTeam($event->teamId, function () use ($event): void {
            $task = self::liveTask($event->taskId, $event->projectId);
            $comment = $task === null ? null : self::liveComment($task, $event->commentId);

            if ($task === null || $comment === null) {
                return;
            }

            $watchers = self::recipients(TaskWatchers::of($task), $event->actorId, CommentMentions::of($comment));
            $this->deliver(NotificationType::TaskCommented, $task, $event->actorId, $watchers);
        });
    }

    public function usersMentioned(UsersMentioned $event): void
    {
        $this->withinTeam($event->teamId, function () use ($event): void {
            $task = self::liveTask($event->taskId, $event->projectId);
            $comment = $task === null ? null : self::liveComment($task, $event->commentId);

            if ($task === null || $comment === null) {
                return;
            }

            // Still mentioned now: an edit may have removed the mention since.
            $mentioned = array_values(array_intersect($event->userIds, CommentMentions::of($comment)));
            $this->deliver(NotificationType::CommentMentioned, $task, $event->actorId, self::recipients($mentioned, $event->actorId), comment: $comment);
        });
    }

    public function invitationSent(InvitationSent $event): void
    {
        $this->withinTeam($event->teamId, function () use ($event): void {
            $invitation = TeamInvitation::query()->select(TeamInvitation::COLUMNS)
                ->whereKey($event->invitationId)
                ->where('team_id', $event->teamId)
                ->whereNotNull('email')
                ->whereNull('revoked_at')
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->first();

            if (! $invitation instanceof TeamInvitation || $invitation->email === null) {
                return;
            }

            // Only a verified address is someone's: an unverified account could have been registered by
            // anyone with the address (API_SPEC.md §3.1 "verified matching email").
            $recipient = User::query()
                ->where('email', $invitation->email)
                ->whereNotNull('email_verified_at')
                ->whereNull('disabled_at')
                ->first();

            if (! $recipient instanceof User || $recipient->id === $invitation->invited_by) {
                return;
            }

            if (TeamMembership::query()->where('team_id', $invitation->team_id)->where('user_id', $recipient->id)->exists()) {
                return;
            }

            // In-app only: the invitation itself is the email.
            if (! NotificationPreferences::of($recipient)->inApp) {
                return;
            }

            DB::insert(
                'INSERT INTO notifications (id, recipient_user_id, team_id, invitation_id, actor_user_id, type, payload, created_at, updated_at) '
                ."VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, '{}'::jsonb, ?, ?) "
                ."ON CONFLICT (recipient_user_id, invitation_id) WHERE type = 'team.invitation' DO NOTHING",
                [(string) Str::uuid7(), $recipient->id, $invitation->team_id, $invitation->id, $invitation->invited_by, NotificationType::TeamInvitation->value, self::now(), self::now()],
            );
        });
    }

    /**
     * Who an event is for (the first of the two "never the actor" layers): `$candidates` without the
     * actor, without `$except`, without duplicates or nulls, in their order. deliver() drops the actor
     * again on its own (the second layer), so neither layer depends on the other.
     *
     * @internal public for NotificationTriggersTest only
     *
     * @param  list<string|null>  $candidates
     * @param  list<string|null>  $except
     * @return list<string>
     */
    public static function recipients(array $candidates, string $actorId, array $except = []): array
    {
        $excluded = [$actorId, ...$except];
        $recipients = [];

        foreach ($candidates as $id) {
            if ($id !== null && ! in_array($id, $excluded, true) && ! in_array($id, $recipients, true)) {
                $recipients[] = $id;
            }
        }

        return $recipients;
    }

    /**
     * Records `$type` for each recipient who can see the task's project now and wants notifications,
     * in a fixed order (by user ID), so concurrent deliveries never deadlock on the unique indexes.
     * Never the actor, whatever the caller passed (the second "never the actor" layer).
     *
     * @param  list<string|null>  $userIds
     * @param  array{fields?: list<string>}  $payload
     */
    private function deliver(NotificationType $type, Task $task, string $actorId, array $userIds, array $payload = [], ?TaskComment $comment = null): void
    {
        $candidates = [];

        foreach ($userIds as $id) {
            if ($id !== null && $id !== $actorId && ! in_array($id, $candidates, true)) {
                $candidates[] = $id;
            }
        }

        $recipients = ProjectAudience::filter($task->project_id, $candidates);
        $preferences = NotificationPreferences::ofMany($recipients);
        $now = self::now();

        foreach ($recipients as $userId) {
            $wants = $preferences[$userId];

            if (! $wants->wantsAny()) {
                continue;
            }

            $id = $type->collapses()
                ? $this->collapse($type, $userId, $task, $actorId, $payload, $now)
                : $this->insertMention($userId, $task, $actorId, $comment, $now);

            if ($id !== null && $wants->email && $type->emails()) {
                SendNotificationEmail::schedule($id);
            }
        }
    }

    /**
     * @param  array{fields?: list<string>}  $payload
     */
    private function collapse(NotificationType $type, string $userId, Task $task, string $actorId, array $payload, string $now): ?string
    {
        $row = DB::selectOne(
            'INSERT INTO notifications (id, recipient_user_id, team_id, project_id, task_id, actor_user_id, type, payload, event_count, created_at, updated_at) '
            .'VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?::jsonb, 1, ?, ?) '
            .'ON CONFLICT (recipient_user_id, task_id, type) WHERE read_at IS NULL AND type IN (\'task.assigned\', \'task.updated\', \'task.commented\') '
            .'DO UPDATE SET actor_user_id = EXCLUDED.actor_user_id, '
            .'event_count = notifications.event_count + 1, '
            .'updated_at = GREATEST(notifications.updated_at, EXCLUDED.updated_at), '
            ."payload = CASE WHEN EXCLUDED.payload -> 'fields' IS NULL THEN notifications.payload ELSE jsonb_build_object('fields', "
            ."(SELECT coalesce(jsonb_agg(DISTINCT f ORDER BY f), '[]'::jsonb) FROM jsonb_array_elements_text(coalesce(notifications.payload -> 'fields', '[]'::jsonb) || (EXCLUDED.payload -> 'fields')) AS f)) END "
            .'RETURNING id',
            [
                (string) Str::uuid7(), $userId, $task->team_id, $task->project_id, $task->id, $actorId, $type->value,
                (string) json_encode($payload === [] ? new stdClass : $payload), $now, $now,
            ],
        );

        return $row instanceof stdClass && is_string($row->id ?? null) ? $row->id : null;
    }

    private function insertMention(string $userId, Task $task, string $actorId, ?TaskComment $comment, string $now): ?string
    {
        if ($comment === null) {
            return null;
        }

        $row = DB::selectOne(
            'INSERT INTO notifications (id, recipient_user_id, team_id, project_id, task_id, comment_id, actor_user_id, type, payload, created_at, updated_at) '
            ."VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, '{}'::jsonb, ?, ?) "
            ."ON CONFLICT (recipient_user_id, comment_id) WHERE type = 'comment.mentioned' DO NOTHING RETURNING id",
            [(string) Str::uuid7(), $userId, $task->team_id, $task->project_id, $task->id, $comment->id, $actorId, NotificationType::CommentMentioned->value, $now, $now],
        );

        return $row instanceof stdClass && is_string($row->id ?? null) ? $row->id : null;
    }

    /**
     * Runs `$deliver` in a transaction holding a SHARE lock on the live team row; a trashed or missing
     * team sends nothing.
     *
     * @param  Closure(): void  $deliver
     */
    private function withinTeam(string $teamId, Closure $deliver): void
    {
        DB::transaction(function () use ($teamId, $deliver): void {
            $team = DB::selectOne('SELECT id FROM teams WHERE id = ?::uuid AND deleted_at IS NULL FOR SHARE', [$teamId]);

            if ($team !== null) {
                $deliver();
            }
        });
    }

    /**
     * The live task of a live project, or null.
     */
    private static function liveTask(string $taskId, string $projectId): ?Task
    {
        $project = Project::query()->whereKey($projectId)->first();

        if (! $project instanceof Project) {
            return null;
        }

        $task = Task::query()->whereKey($taskId)->where('project_id', $project->id)->first();

        return $task instanceof Task ? $task->setRelation('project', $project) : null;
    }

    private static function liveComment(Task $task, string $commentId): ?TaskComment
    {
        $comment = $task->comments()->whereKey($commentId)->first();

        return $comment instanceof TaskComment ? $comment : null;
    }

    /**
     * The field names a `task.updated` notification may carry (the `task.updated` activity's names).
     *
     * @param  list<string>  $fields
     * @return list<string>
     */
    private static function fields(array $fields): array
    {
        $known = array_values(array_intersect(InboxPresenter::TASK_FIELDS, $fields));
        sort($known);

        return $known;
    }

    private static function now(): string
    {
        return now()->utc()->format('Y-m-d H:i:s');
    }
}
