<?php

namespace App\Domain\Notifications;

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * A user's notification preferences (DATABASE.md §8 `user_preferences.email_notifications`,
 * `in_app_notifications`; API_SPEC.md §2 PATCH /me; FR-COL-004; CORE-009, ADR-029 §5). Both default
 * to on, as the columns do; a user without a preferences row has the defaults.
 *
 * - `in_app` off: the inbox and the unread count show nothing. Notifications are still recorded (so
 *   an email can be sent), and they appear again if the user turns the inbox back on.
 * - `email` off: nothing is emailed; mail already queued is dropped at send time.
 */
final class NotificationPreferences
{
    public function __construct(
        public readonly bool $email,
        public readonly bool $inApp,
    ) {}

    public static function of(User $user): self
    {
        $preferences = $user->preferences;

        return $preferences instanceof UserPreference
            ? new self($preferences->email_notifications, $preferences->in_app_notifications)
            : new self(UserPreference::DEFAULT_NOTIFICATIONS, UserPreference::DEFAULT_NOTIFICATIONS);
    }

    /**
     * The preferences of many users at once, keyed by user ID (defaults for users without a row).
     *
     * @param  list<string>  $userIds
     * @return array<string, self>
     */
    public static function ofMany(array $userIds): array
    {
        $preferences = [];

        foreach ($userIds as $id) {
            $preferences[$id] = new self(UserPreference::DEFAULT_NOTIFICATIONS, UserPreference::DEFAULT_NOTIFICATIONS);
        }

        if ($userIds === []) {
            return $preferences;
        }

        $rows = DB::table('user_preferences')
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'email_notifications', 'in_app_notifications']);

        foreach ($rows as $row) {
            if ($row instanceof stdClass && is_string($row->user_id ?? null)) {
                $preferences[$row->user_id] = new self((bool) $row->email_notifications, (bool) $row->in_app_notifications);
            }
        }

        return $preferences;
    }

    /** Whether anything should be recorded for the user at all. */
    public function wantsAny(): bool
    {
        return $this->email || $this->inApp;
    }
}
