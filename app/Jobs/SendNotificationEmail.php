<?php

namespace App\Jobs;

use App\Domain\Notifications\Inbox;
use App\Domain\Notifications\InboxPresenter;
use App\Domain\Notifications\NotificationPreferences;
use App\Models\InboxNotification;
use App\Models\User;
use App\Notifications\Inbox\InboxNotificationMail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The email for one notification (API_SPEC.md §9 "Email notifications are queued and respect
 * per-user preferences"; FR-COL-004; CORE-009, ADR-029 §5).
 *
 * - Scheduled once per open window: schedule() sets `email_due_at` only where it is empty (or the
 *   window was lost, below), and dispatches the job after the commit, delayed by
 *   `sn3.notifications.email_delay_seconds`. Events collapsed into the notification meanwhile go out
 *   in this one email.
 * - The payload is the notification ID only (encrypted on the queue all the same). When it runs, it
 *   locks the row and re-checks everything: the row still exists and is due; the recipient is enabled,
 *   verified and still wants email; they may still see what it is about (Inbox, the read-time
 *   predicate); and, if they use the inbox, they have not read it there already. Otherwise nothing is
 *   sent. Titles and names are read now (InboxPresenter), never carried by the queue.
 * - Either way the window closes (`email_due_at` cleared), so the next event opens a new one.
 * - **A window never stays open for good.** If every try fails (an SMTP error rolls the transaction
 *   back), failed() closes the window. If the job was lost before it ran (a queue flushed, a worker
 *   gone), the next event reopens a window that is overdue by RECOVERY_GRACE_SECONDS and queues a new
 *   job. The grace is far longer than the retries' backoff, so a healthy window, whose job is waiting
 *   or retrying, is never queued twice. Delivery stays at least once: a job that was only very late
 *   and a recovery job may both run, and the first to lock the row sends the email.
 */
final class SendNotificationEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /**
     * How long past its due time a window counts as lost, so the next event reopens it. Well above
     * the retries (`$backoff`) and a busy worker's lag.
     */
    public const RECOVERY_GRACE_SECONDS = 900;

    public function __construct(public readonly string $notificationId)
    {
        $this->afterCommit();
    }

    /**
     * Opens the notification's email window unless a healthy one is open, and queues the email for its
     * end. A window overdue by more than RECOVERY_GRACE_SECONDS lost its job, and is reopened.
     */
    public static function schedule(string $notificationId): void
    {
        $delay = max(0, config()->integer('sn3.notifications.email_delay_seconds', 120));
        $due = now()->addSeconds($delay);

        $opened = DB::table('notifications')
            ->where('id', $notificationId)
            ->where(function (Builder $window): void {
                $window->whereNull('email_due_at')
                    ->orWhere('email_due_at', '<', now()->subSeconds(self::RECOVERY_GRACE_SECONDS));
            })
            ->update(['email_due_at' => $due]);

        if ($opened === 1) {
            self::dispatch($notificationId)->delay($due);
        }
    }

    public function handle(Inbox $inbox, InboxPresenter $presenter): void
    {
        DB::transaction(function () use ($inbox, $presenter): void {
            $notification = InboxNotification::query()->whereKey($this->notificationId)->lockForUpdate()->first();

            if (! $notification instanceof InboxNotification || $notification->email_due_at === null) {
                return;
            }

            $recipient = User::query()->whereKey($notification->recipient_user_id)->first();
            $view = $recipient instanceof User && $this->wanted($notification, $recipient, $inbox)
                ? $presenter->one($notification)
                : null;

            if ($recipient instanceof User && $view !== null) {
                $recipient->notifyNow(new InboxNotificationMail($view));
            }

            DB::table('notifications')->where('id', $notification->id)->update([
                'email_due_at' => null,
                'emailed_at' => $view !== null ? now() : $notification->emailed_at,
            ]);
        });
    }

    /**
     * Every try failed: close the window, so the next event opens a new one (and emails what this one
     * could not). A window reopened meanwhile, still in the future, belongs to another job and stays.
     */
    public function failed(?Throwable $exception = null): void
    {
        DB::table('notifications')
            ->where('id', $this->notificationId)
            ->where('email_due_at', '<=', now())
            ->update(['email_due_at' => null]);

        Log::warning('notification_email.failed', [
            'notification_id' => $this->notificationId,
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }

    private function wanted(InboxNotification $notification, User $recipient, Inbox $inbox): bool
    {
        if ($recipient->isDisabled() || ! $recipient->hasVerifiedEmail() || ! $notification->type->emails()) {
            return false;
        }

        $preferences = NotificationPreferences::of($recipient);

        if (! $preferences->email || ($preferences->inApp && $notification->read_at !== null)) {
            return false;
        }

        return $inbox->visibleTo($recipient, ignoreInAppPreference: true)->whereKey($notification->id)->exists();
    }
}
