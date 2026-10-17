<?php

namespace App\Domain\Invitations;

/**
 * Invitation tokens (API_SPEC.md §3.1, DATABASE.md §3, ADR-025 §2): 32 random bytes from the
 * operating system's CSPRNG, written as unpadded base64url (43 characters). Only their SHA-256 is
 * stored, so a database leak does not reveal usable tokens.
 */
final class InvitationToken
{
    /** Route constraint for `{token}`: anything else is a 404 before any lookup. */
    public const PATTERN = '[A-Za-z0-9_-]{43}';

    /**
     * @return array{token: string, hash: string} The raw token and its lower-case hex SHA-256.
     */
    public static function generate(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['token' => $token, 'hash' => self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match('/\A'.self::PATTERN.'\z/', $token) === 1;
    }
}
