<?php

namespace Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Shape FND-004 mail must follow: a queued notification on the mail channel. */
class QueuedMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const SECRET = 'fixture-signature-0123456789';

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Fixture subject')
            ->line('Fixture body line.')
            ->action('Verify', 'https://sn3.test/verify?expires=1&signature='.self::SECRET);
    }
}
