<?php

namespace App\Notifications\Inbox;

use App\Domain\Notifications\InboxPresenter;
use App\Notifications\PlainMarkdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email for one inbox notification (API_SPEC.md §9; FR-COL-004, FR-I18N-001; CORE-009, ADR-029 §5).
 *
 * Sent by SendNotificationEmail with notifyNow(), inside the job that re-checked access a moment
 * before: it is never queued itself, so the titles and names it carries never sit on the queue. Written
 * in the recipient's language (User::preferredLocale()). Names, titles and project names are
 * user-written: Markdown-escaped in the body (PlainMarkdown) and reduced to one line in the subject.
 *
 * @phpstan-import-type NotificationView from InboxPresenter
 */
final class InboxNotificationMail extends Notification
{
    /**
     * @param  NotificationView  $view  InboxPresenter's representation of the notification
     */
    public function __construct(public readonly array $view) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $view = $this->view;
        $type = $view['type'];
        $count = $view['event_count'];
        $many = $count > 1 && in_array($type, ['task.updated', 'task.commented'], true);
        $key = $view['task']['key'] ?? '';
        $actor = $view['actor']['name'] ?? self::text('someone');
        $headline = 'types.'.str_replace('.', '_', $type).'.'.($many ? 'many' : 'one');

        $mail = (new MailMessage)
            ->subject(self::oneLine(self::text($headline, ['actor' => $actor, 'key' => $key, 'count' => (string) $count])))
            ->line(self::text($headline, ['actor' => PlainMarkdown::escape($actor), 'key' => $key, 'count' => (string) $count]))
            ->line(self::text('task', ['key' => $key, 'title' => PlainMarkdown::escape($view['task']['title'] ?? '')]))
            ->line(self::text('where', [
                'project' => PlainMarkdown::escape($view['project']['name'] ?? ''),
                'team' => PlainMarkdown::escape($view['team']['name']),
            ]));

        if ($type === 'task.updated' && $view['fields'] !== []) {
            $names = array_map(static fn (string $field): string => self::text('fields.'.$field), $view['fields']);
            $mail->line(self::text('changed', ['fields' => implode(self::text('separator'), $names)]));
        }

        return $mail
            ->action(self::text('action'), url('/tasks/'.rawurlencode($view['task']['id'] ?? '')))
            ->line(self::text('outro', ['url' => url('/settings')]));
    }

    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', $value));
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __('notifications.inbox.'.$key, $replace);

        return is_string($text) ? $text : $key;
    }
}
