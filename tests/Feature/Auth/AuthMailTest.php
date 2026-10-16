<?php

use App\Models\User;
use App\Notifications\Auth\AccountAlreadyExistsNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

// Auth mail is queued (ADR-015 convention) and rendered in the recipient's language and direction.

uses(RefreshDatabase::class);

it('queues every auth notification', function (string $class) {
    expect(new ReflectionClass($class))->implementsInterface(ShouldQueue::class)->toBeTrue();
})->with([VerifyEmailNotification::class, ResetPasswordNotification::class, AccountAlreadyExistsNotification::class]);

it('renders in the recipient locale with the matching direction', function (string $locale, string $dir, string $subject) {
    $user = User::factory()->unverified()->create(['locale' => $locale]);

    Notification::fake();
    $user->sendEmailVerificationNotification();

    Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification, array $channels, object $notifiable, string $sentLocale) use ($user, $locale, $dir, $subject) {
        expect($sentLocale)->toBe($locale);

        app()->setLocale($sentLocale);
        $mail = $notification->toMail($user);
        $html = (string) $mail->render();

        expect($mail->subject)->toBe($subject)
            ->and($html)->toContain('lang="'.$locale.'" dir="'.$dir.'"')
            ->and($html)->toContain('<body dir="'.$dir.'"');

        return true;
    });
})->with([
    'arabic' => ['ar', 'rtl', 'تأكيد بريدك الإلكتروني'],
    'english' => ['en', 'ltr', 'Verify your email address'],
]);
