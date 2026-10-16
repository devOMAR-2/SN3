<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Queued, localized password-reset email. The link opens the Inertia page
 * /reset-password/{token}?email=…, which submits to POST /api/v1/auth/password/reset.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        $broker = (string) config('auth.defaults.passwords');
        $minutes = (int) config("auth.passwords.{$broker}.expire");

        return (new MailMessage)
            ->subject(__('notifications.reset_password.subject'))
            ->line(__('notifications.reset_password.intro'))
            ->action(__('notifications.reset_password.action'), $url)
            ->line(__('notifications.reset_password.expiry', ['minutes' => $minutes]))
            ->line(__('notifications.reset_password.outro'));
    }
}
