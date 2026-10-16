<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent instead of a second account when someone registers with an existing email,
 * so the registration response can stay identical and not reveal which emails exist
 * (API_SPEC.md §2: generic responses for email existence).
 */
class AccountAlreadyExistsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.account_exists.subject'))
            ->line(__('notifications.account_exists.intro'))
            ->action(__('notifications.account_exists.action'), route('login'))
            ->line(__('notifications.account_exists.reset_hint', ['url' => route('password.request')]))
            ->line(__('notifications.account_exists.outro'));
    }
}
