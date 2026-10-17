<?php

namespace App\Jobs;

use App\Domain\Notifications\Inbox;
use App\Domain\Notifications\InboxPresenter;
use App\Domain\Notifications\NotificationPreferences;
use App\Models\InboxNotification;
use App\Models\User;
use App\Notifications\Inbox\InboxNotificationMail;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
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
 * - The payload is the notification ID and the due time of the job's own window (encrypted on the
 *   queue all the same). When it runs, it locks the row and re-checks everything: the row still exists
 *   and its open window is still this job's (`email_due_at` equals `dueAt`); the recipient is enabled,
 *   verified and still wants email; they may still see what it is about (Inbox, the read-time
 *   predicate); and, if they use the inbox, they have not read it there already. Otherwise nothing is
 *   sent. Titles and names are read now (InboxPresenter), never carried by the queue.
 * - Either way the window closes (`email_due_at` cleared), so the next event opens a new one.
 * - **A window never stays open for good.** If every try fails (an SMTP error rolls the transaction
 *   back), failed() closes the job's own window. If the job was lost before it ran (a queue flushed, a
 *   worker gone), the next event reopens a window that is overdue by RECOVERY_GRACE_SECONDS and queues
 *   a new job. The grace is far longer than the delay plus the retries' backoff, so a healthy window,
 *   whose job is waiting or retrying, is never queued twice.
 * - **Each job owns one window.** A job whose window was closed or replaced (a late job after a
 *   recovery reopened the window) changes nothing and sends nothing: only the job of the open window
 *   sends it, at that window's end, and only its failure closes it. So one window is never emailed
 *   twice or early, and a late failure never closes a newer window.
 * - After a failure or a lost job, the email goes out only when a later event on the notification
 *   reopens a window; nothing retries by itself.
 * - Jobs queued before `dueAt` existed (null) keep the earlier rule: any open window is theirs.
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

    /**
     * @param  string|null  $dueAt  the job's own window end (`email_due_at`, ISO 8601 UTC, whole seconds)
     */
    public function __construct(public readonly string $notificationId, public readonly ?string $dueAt = null)
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
        // Whole seconds: the job compares its window with the stored column exactly.
        $due = now()->addSeconds($delay)->startOfSecond();

        $opened = DB::table('notifications')
            ->where('id', $notificationId)
            ->where(function (Builder $window): void {
                $window->whereNull('email_due_at')
                    ->orWhere('email_due_at', '<', now()->subSeconds(self::RECOVERY_GRACE_SECONDS));
            })
            ->update(['email_due_at' => $due]);

        if ($opened === 1) {
            self::dispatch($notificationId, $due->toIso8601ZuluString())->delay($due);
        }
    }

    public function handle(Inbox $inbox, InboxPresenter $presenter): void
    {
        DB::transaction(function () use ($inbox, $presenter): void {
            $notification = InboxNotification::query()->whereKey($this->notificationId)->lockForUpdate()->first();

            if (! $notification instanceof InboxNotification || ! $this->ownsWindow($notification->email_due_at)) {
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
     * Every try failed: close this job's window, so the next event opens a new one (and emails what
     * this one could not). A window reopened meanwhile belongs to another job and stays open.
     */
    public function failed(?Throwable $exception = null): void
    {
        $window = DB::table('notifications')->where('id', $this->notificationId);

        if ($this->dueAt !== null) {
            $window->where('email_due_at', Carbon::parse($this->dueAt));
        } else {
            $window->where('email_due_at', '<=', now());
        }

        $window->update(['email_due_at' => null]);

        Log::warning('notification_email.failed', [
            'notification_id' => $this->notificationId,
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }

    /**
     * Whether the notification's open window is this job's (any open window for a job queued before
     * `dueAt` existed).
     */
    private function ownsWindow(?CarbonInterface $openWindow): bool
    {
        if ($openWindow === null) {
            return false;
        }

        return $this->dueAt === null || $openWindow->equalTo(Carbon::parse($this->dueAt));
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
