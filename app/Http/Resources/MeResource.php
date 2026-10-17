<?php

namespace App\Http\Resources;

use App\Domain\Notifications\NotificationPreferences;
use App\Models\User;

/**
 * The current user's own representation (GET/PATCH /me, login response, Inertia `auth.user`).
 * Contains only the caller's own data; never secrets or tokens.
 */
final class MeResource
{
    /**
     * @return array{id: string, name: string, email: string, email_verified: bool, email_verified_at: string|null, locale: string, timezone: string, theme: string, email_notifications: bool, in_app_notifications: bool, created_at: string|null}
     */
    public static function make(User $user): array
    {
        $notifications = NotificationPreferences::of($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'email_verified_at' => $user->email_verified_at?->utc()->toIso8601ZuluString(),
            'locale' => $user->locale,
            'timezone' => $user->timezone,
            'theme' => $user->theme(),
            'email_notifications' => $notifications->email,
            'in_app_notifications' => $notifications->inApp,
            'created_at' => $user->created_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
