<?php

namespace App\Domain\Notifications;

/**
 * What a notification is about (API_SPEC.md §9 "Notifications triggered by assignment, comment mention,
 * invitation, and task update for watchers"; FR-COL-003, FR-COL-004; CORE-009, ADR-029 §2).
 *
 * The task types collapse: while one is unread, further events of the same type on the same task for
 * the same recipient update it (`event_count`, latest actor and time) instead of adding rows
 * ("batch/dedupe noisy events"). A mention is one notification per comment and user; an invitation one
 * per invitation.
 */
enum NotificationType: string
{
    /** Someone else made the recipient the task's assignee. */
    case TaskAssigned = 'task.assigned';

    /** A task the recipient watches was edited (field names only). */
    case TaskUpdated = 'task.updated';

    /** A task the recipient watches got a new comment. */
    case TaskCommented = 'task.commented';

    /** The recipient was mentioned in a comment. */
    case CommentMentioned = 'comment.mentioned';

    /** An email invitation to a team went to the recipient's verified address (in-app only). */
    case TeamInvitation = 'team.invitation';

    public function collapses(): bool
    {
        return in_array($this, [self::TaskAssigned, self::TaskUpdated, self::TaskCommented], true);
    }

    /** Whether the recipient may also get it by email (an invitation already is an email). */
    public function emails(): bool
    {
        return $this !== self::TeamInvitation;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
