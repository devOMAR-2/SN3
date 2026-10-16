<?php

namespace App\Support;

/**
 * The interface languages SN3 supports (AGENTS.md §2, DATABASE.md §3 `users.locale`).
 *
 * Locale only selects language and layout direction; it is never an authorization signal.
 */
enum Locale: string
{
    case Arabic = 'ar';
    case English = 'en';

    /** Fallback when neither the user, the session nor the browser names a supported locale (API_SPEC.md §1.1). */
    public const self DEFAULT = self::Arabic;

    /** Session key that stores a guest's explicit choice. */
    public const string SESSION_KEY = 'locale';

    public function direction(): string
    {
        return $this === self::Arabic ? 'rtl' : 'ltr';
    }

    /**
     * Exact match on a supported code. Anything else (including region tags) is rejected.
     */
    public static function fromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
