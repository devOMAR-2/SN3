<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Queued, localized password-reset email. The link opens the Inertia page
 * /reset-password#token=…&email=…, which submits to POST /api/v1/auth/password/reset.
 *
 * Token and email travel in the URL fragment: browsers never send it to the server, so they cannot
 * appear in access logs, proxy logs or Referer headers.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /**
     * @param  mixed  $notifiable
     */
    protected function resetUrl($notifiable): string
    {
        $email = $notifiable instanceof CanResetPassword ? $notifiable->getEmailForPasswordReset() : '';

        return url('/reset-password').'#'.http_build_query(
            ['token' => $this->token, 'email' => $email],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }

    /**
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        $broker = config()->string('auth.defaults.passwords');
        $minutes = config()->integer("auth.passwords.{$broker}.expire");

        return (new MailMessage)
            ->subject(__('notifications.reset_password.subject'))
            ->line(__('notifications.reset_password.intro'))
            ->action(__('notifications.reset_password.action'), $url)
            ->line(__('notifications.reset_password.expiry', ['minutes' => $minutes]))
            ->line(__('notifications.reset_password.outro'));
    }
}
