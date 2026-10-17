<?php

namespace App\Domain\Notifications;

use App\Models\InboxNotification;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\AccessibleProjects;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A user's inbox (API_SPEC.md §9; FR-COL-003, FR-COL-005, NOT-01; CORE-009, ADR-029 §3).
 *
 * **Access is re-checked at read time, on every read.** A notification is in the inbox only while its
 * recipient may see what it is about *now*:
 *
 * - task notifications: the project passes AccessibleProjects for the recipient (team membership,
 *   private-project membership or Team Owner; team and project not in the trash), and the task is
 *   live. A mention also needs the comment to be live and to still mention the recipient;
 * - invitation notifications: the invitation is still pending, is addressed to the recipient's
 *   verified email, its team is not in the trash, and the recipient has not joined it meanwhile.
 *
 * Everything else is left out of the list, the unread count, "mark read" (404) and "mark all read",
 * exactly as if it did not exist, so no count or answer reveals that something hidden is there. One
 * predicate covers every way access ends (team removal or leaving, demotion, removal from a private
 * project, a project made private, a trashed project or task, an ownership transfer) and comes back
 * with it when access does. Rows are never rendered from a copy: titles and names are read from the
 * live rows (InboxPresenter).
 */
final class Inbox
{
    public function __construct(private readonly AccessibleProjects $projects) {}

    /**
     * The user's visible notifications. With `$ignoreInAppPreference` the in-app preference is not
     * applied (the email job: email is a separate preference).
     *
     * @return Builder<InboxNotification>
     */
    public function visibleTo(User $user, bool $ignoreInAppPreference = false): Builder
    {
        $query = InboxNotification::query()->where('notifications.recipient_user_id', $user->id);

        if (! $ignoreInAppPreference && ! NotificationPreferences::of($user)->inApp) {
            return $query->whereRaw('false');
        }

        $projects = $this->projects->constrain(Project::query(), $user)->select('projects.id');

        return $query->where(function (Builder $visible) use ($user, $projects): void {
            $visible->where(function (Builder $task) use ($projects): void {
                $task->whereNotNull('notifications.task_id')
                    ->whereIn('notifications.project_id', $projects)
                    ->whereExists(function (QueryBuilder $live): void {
                        $live->selectRaw('1')->from('tasks')
                            ->whereColumn('tasks.id', 'notifications.task_id')
                            ->whereNull('tasks.deleted_at');
                    })
                    ->where(function (Builder $comment): void {
                        $comment->whereNull('notifications.comment_id')
                            ->orWhereExists(function (QueryBuilder $mention): void {
                                $mention->selectRaw('1')->from('task_comment_mentions as m')
                                    ->join('task_comments as c', 'c.id', '=', 'm.comment_id')
                                    ->whereColumn('m.comment_id', 'notifications.comment_id')
                                    ->whereColumn('m.user_id', 'notifications.recipient_user_id')
                                    ->whereNull('c.deleted_at');
                            });
                    });
            });

            // An invitation is for whoever holds the verified address; nobody else ever sees it.
            if ($user->hasVerifiedEmail() && ! $user->isDisabled()) {
                $visible->orWhere(function (Builder $invitation) use ($user): void {
                    $invitation->where('notifications.type', NotificationType::TeamInvitation->value)
                        ->whereExists(function (QueryBuilder $pending) use ($user): void {
                            $pending->selectRaw('1')->from('team_invitations as i')
                                ->join('teams as t', 't.id', '=', 'i.team_id')
                                ->whereColumn('i.id', 'notifications.invitation_id')
                                ->where('i.email', $user->email)
                                ->whereNull('i.revoked_at')
                                ->whereNull('i.accepted_at')
                                ->whereColumn('i.use_count', '<', 'i.max_uses')
                                ->where('i.expires_at', '>', now())
                                ->whereNull('t.deleted_at');
                        })
                        ->whereNotExists(function (QueryBuilder $member) use ($user): void {
                            $member->selectRaw('1')->from('team_memberships as tm')
                                ->whereColumn('tm.team_id', 'notifications.team_id')
                                ->where('tm.user_id', $user->id);
                        });
                });
            }
        });
    }

    /**
     * The inbox list: latest event first, ties by ID (API_SPEC.md §1.4), optionally unread only.
     *
     * @return Builder<InboxNotification>
     */
    public function list(User $user, bool $unreadOnly = false): Builder
    {
        $query = $this->visibleTo($user);

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return $query->orderByDesc('updated_at')->orderByDesc('id');
    }

    public function unreadCount(User $user): int
    {
        return $this->visibleTo($user)->whereNull('read_at')->count();
    }

    /**
     * A visible notification of the user; anything else (someone else's, hidden, random, malformed)
     * is the same 404.
     *
     * @throws ModelNotFoundException<InboxNotification>
     */
    public function findOrFail(User $user, string $id): InboxNotification
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(InboxNotification::class, [$id]);
        }

        return $this->visibleTo($user)->whereKey(strtolower($id))->firstOrFail();
    }

    /**
     * Marks one visible notification read; already read stays as it was (idempotent).
     */
    public function markRead(User $user, InboxNotification $notification): InboxNotification
    {
        DB::table('notifications')
            ->where('id', $notification->id)
            ->where('recipient_user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $notification->refresh();
    }

    /**
     * Marks every visible unread notification read (API_SPEC.md §9 "Mark own visible inbox read").
     * Hidden ones are left alone: nothing about them changes, so nothing can be learned from them.
     *
     * @return int how many were marked
     */
    public function markAllRead(User $user): int
    {
        $ids = $this->visibleTo($user)->whereNull('read_at')->select('notifications.id');

        return DB::table('notifications')
            ->where('recipient_user_id', $user->id)
            ->whereNull('read_at')
            ->whereIn('id', $ids)
            ->update(['read_at' => now()]);
    }
}
