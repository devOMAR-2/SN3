<?php

namespace App\Notifications\Teams;

use App\Domain\Teams\TeamRole;
use App\Notifications\PlainMarkdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * The email for an email-bound team invitation (API_SPEC.md §3.1; CORE-002, ADR-025 §2, §3).
 *
 * - Queued after the creating transaction commits, and encrypted on the queue: the payload carries
 *   the raw token, which must never sit in plain text in the `jobs` or `failed_jobs` tables
 *   (DATABASE.md §0 rule 6).
 * - The link opens the Inertia page `/invite#<token>`. The token travels in the URL fragment, which
 *   browsers never send to the server, so it cannot reach access logs, proxies or Referer headers.
 * - Written in the recipient's language when the address has an account, else the inviter's.
 * - Team and inviter names are user-written: they are escaped so they render as plain text (plain()).
 */
class TeamInvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $teamName,
        public readonly string $inviterName,
        public readonly TeamRole $role,
        public readonly string $token,
        public readonly Carbon $expiresAt,
        string $locale,
    ) {
        $this->locale = $locale;
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public static function url(string $token): string
    {
        return url('/invite').'#'.$token;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(self::text('subject', ['team' => $this->teamName]))
            ->line(self::text('intro', [
                'inviter' => self::plain($this->inviterName),
                'team' => self::plain($this->teamName),
                'role' => self::text('roles.'.$this->role->value),
            ]))
            ->action(self::text('action'), self::url($this->token))
            ->line(self::text('expiry', ['days' => (string) max(1, (int) ceil(now()->diffInHours($this->expiresAt) / 24))]))
            ->line(self::text('verified'))
            ->line(self::text('outro'));
    }

    /**
     * User-written text (team and inviter names) as inert Markdown (PlainMarkdown::escape()).
     */
    public static function plain(string $value): string
    {
        return PlainMarkdown::escape($value);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __('notifications.team_invitation.'.$key, $replace);

        return is_string($text) ? $text : $key;
    }
}
