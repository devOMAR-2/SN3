<?php

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Removes secrets from log records before any handler or formatter sees them
 * (AGENTS.md §6.8, API_SPEC.md §13).
 *
 * - Values under sensitive keys are replaced, recursively, in `context` and `extra`.
 *   Keys are compared case-insensitively with separators removed, so `api_key`,
 *   `X-Api-Key` and `apiKey` all match.
 * - Sensitive query parameters inside strings (e.g. a signed URL's `signature=`)
 *   are replaced in the message and in string values, and so are invitation tokens in
 *   `/invitations/<token>` paths and `/invite#<token>` links.
 *
 * This is a safety net, not permission to log request payloads: code must still
 * avoid logging personal data and private project content in the first place.
 */
class RedactSensitiveData implements ProcessorInterface
{
    public const REPLACEMENT = '[REDACTED]';

    /**
     * A normalized key containing any of these fragments is sensitive.
     *
     * @var list<string>
     */
    private const SENSITIVE_FRAGMENTS = [
        'password',
        'passwd',
        'secret',
        'token',
        'authorization',
        'cookie',
        'signature',
        'apikey',
        'privatekey',
        'credential',
    ];

    private const MAX_DEPTH = 16;

    /** `/invitations/<token>` and `/invite#<token>` (ADR-025 §2). */
    private const INVITATION_TOKEN_PATTERN = '~(/invitations/|/invite#)[A-Za-z0-9_-]{43}(?![A-Za-z0-9_-])~';

    private const QUERY_PARAMETER_PATTERN = '/([?&])([^=&#\s"\'<>]+)=([^&#\s"\'<>]*)/';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactString($record->message),
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra),
        );
    }

    public static function isSensitiveKey(string|int $key): bool
    {
        if (is_int($key)) {
            return false;
        }

        $normalized = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $key));

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redactArray(array $data, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [self::REPLACEMENT];
        }

        foreach ($data as $key => $value) {
            if (self::isSensitiveKey($key)) {
                $data[$key] = self::REPLACEMENT;
            } elseif (is_array($value)) {
                $data[$key] = $this->redactArray($value, $depth + 1);
            } elseif (is_string($value)) {
                $data[$key] = $this->redactString($value);
            }
        }

        return $data;
    }

    public function redactString(string $value): string
    {
        // Invitation tokens travel in the API path (API_SPEC.md §3.1) and the `/invite#…` fragment.
        if (str_contains($value, 'invit')) {
            $value = (string) preg_replace(self::INVITATION_TOKEN_PATTERN, '$1'.self::REPLACEMENT, $value);
        }

        if (! str_contains($value, '=')) {
            return $value;
        }

        return (string) preg_replace_callback(
            self::QUERY_PARAMETER_PATTERN,
            static fn (array $match): string => self::isSensitiveKey(rawurldecode($match[2]))
                ? $match[1].$match[2].'='.self::REPLACEMENT
                : $match[0],
            $value,
        );
    }
}
