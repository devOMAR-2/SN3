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
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * The email for one notification (API_SPEC.md §9 "Email notifications are queued and respect
 * per-user preferences"; FR-COL-004; CORE-009, ADR-029 §5).
 *
 * - Scheduled once per open window: schedule() sets `email_due_at` only where it is empty, and
 *   dispatches the job after the commit, delayed by `sn3.notifications.email_delay_seconds`. Events
 *   collapsed into the notification meanwhile go out in this one email.
 * - The payload is the notification ID only (encrypted on the queue all the same). When it runs, it
 *   locks the row and re-checks everything: the row still exists and is due; the recipient is enabled,
 *   verified and still wants email; they may still see what it is about (Inbox, the read-time
 *   predicate); and, if they use the inbox, they have not read it there already. Otherwise nothing is
 *   sent. Titles and names are read now (InboxPresenter), never carried by the queue.
 * - Either way the window closes (`email_due_at` cleared), so the next event opens a new one.
 */
final class SendNotificationEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $notificationId)
    {
        $this->afterCommit();
    }

    /**
     * Opens the notification's email window unless one is open, and queues the email for its end.
     */
    public static function schedule(string $notificationId): void
    {
        $delay = max(0, config()->integer('sn3.notifications.email_delay_seconds', 120));
        $due = now()->addSeconds($delay);

        $opened = DB::table('notifications')
            ->where('id', $notificationId)
            ->whereNull('email_due_at')
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
