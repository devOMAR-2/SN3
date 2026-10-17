<?php

namespace App\Listeners;

use App\Domain\Comments\Events\CommentAdded;
use App\Domain\Comments\Events\UsersMentioned;
use App\Domain\Invitations\Events\InvitationSent;
use App\Domain\Notifications\Notifier;
use App\Domain\Tasks\Events\TaskChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queues notification delivery for the domain events that cause notifications (CORE-009, ADR-029 §2;
 * ADR-027 §2 "Hook for CORE-009"). The events carry IDs only and fire after their change committed;
 * Notifier re-resolves everything and re-checks access when the job runs. Retried by the worker
 * (`--tries=3`): each delivery is one transaction, so a failed attempt leaves nothing behind; mentions
 * and invitations are unique per comment or invitation, so even a job run twice after a worker crash
 * adds no second one (a collapsed task notification may then count one event twice).
 */
final class SendInboxNotifications implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(TaskChanged $event): void
    {
        $this->notifier->taskChanged($event);
    }

    public function handleCommentAdded(CommentAdded $event): void
    {
        $this->notifier->commentAdded($event);
    }

    public function handleUsersMentioned(UsersMentioned $event): void
    {
        $this->notifier->usersMentioned($event);
    }

    public function handleInvitationSent(InvitationSent $event): void
    {
        $this->notifier->invitationSent($event);
    }
}
