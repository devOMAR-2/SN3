<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/**
 * Queued, localized verification email. The link targets
 * GET /api/v1/auth/email/verify/{id}/{hash} as a temporary signed URL (API_SPEC.md §2).
 */
class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    /**
     * @param  mixed  $notifiable
     */
    protected function verificationUrl($notifiable): string
    {
        if (! $notifiable instanceof Model || ! $notifiable instanceof MustVerifyEmail) {
            throw new InvalidArgumentException('Email verification needs a model that implements MustVerifyEmail.');
        }

        return URL::temporarySignedRoute(
            'api.auth.verification.verify',
            Carbon::now()->addMinutes(config()->integer('sn3.verification.expire_minutes')),
            [
                'id' => $notifiable->getKey(),
                'hash' => hash('sha256', $notifiable->getEmailForVerification()),
            ],
        );
    }

    /**
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = config()->integer('sn3.verification.expire_minutes');

        return (new MailMessage)
            ->subject(__('notifications.verify_email.subject'))
            ->line(__('notifications.verify_email.intro'))
            ->action(__('notifications.verify_email.action'), $url)
            ->line(__('notifications.verify_email.expiry', ['minutes' => $minutes]))
            ->line(__('notifications.verify_email.outro'));
    }
}
