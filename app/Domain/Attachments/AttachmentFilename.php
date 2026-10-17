<?php

namespace App\Domain\Attachments;

use Normalizer;

/**
 * Makes a client-supplied file name safe to store and show (API_SPEC.md §6.3 "original filename is
 * metadata only"; CORE-008, ADR-027 §5). The name never becomes a path (keys are random,
 * AttachmentStorage), so this is about display and the download header:
 *
 * - only the last path segment is kept, for `/` and `\` alike, so `../../etc/passwd.txt` is
 *   `passwd.txt` and `C:\Users\x\a.pdf` is `a.pdf`;
 * - control characters, NUL, and the bidirectional overrides and isolates (U+202A–U+202E,
 *   U+2066–U+2069, plus U+200E/U+200F and U+061C) are removed, so a right-to-left override cannot
 *   make `invoice‮fdp.exe` look like a PDF (important in an Arabic-first product);
 * - the result is NFC-normalised, trimmed of spaces and dots at both ends, and cut to 255
 *   characters keeping the extension; `.` and `..` (or nothing at all) become `file`.
 */
final class AttachmentFilename
{
    public const MAX = 255;

    public const FALLBACK = 'file';

    public static function sanitize(string $name): string
    {
        // Invalid UTF-8 cannot be shown faithfully; drop the broken bytes.
        $name = str_replace('\\', '/', mb_convert_encoding($name, 'UTF-8', 'UTF-8'));
        $segments = explode('/', $name);
        $name = (string) end($segments);

        $name = (string) preg_replace('/[\p{Cc}\x{200E}\x{200F}\x{061C}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $name);

        $normalized = Normalizer::normalize($name, Normalizer::FORM_C);
        $name = is_string($normalized) ? $normalized : $name;

        $name = trim($name, " \t.");

        if ($name === '') {
            return self::FALLBACK;
        }

        if (mb_strlen($name) > self::MAX) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $suffix = $extension !== '' && mb_strlen($extension) < 16 ? '.'.$extension : '';
            $name = rtrim(mb_substr($name, 0, self::MAX - mb_strlen($suffix)), " \t.").$suffix;
        }

        return $name;
    }

    /** An ASCII-only stand-in for the `filename=` parameter of Content-Disposition. */
    public static function asciiFallback(string $name): string
    {
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9._ -]/', '_', $name), ' .');

        // Nothing readable left of the name itself (e.g. an Arabic name): a neutral one, same extension.
        return trim(pathinfo($ascii, PATHINFO_FILENAME), '_ .-') === '' ? self::FALLBACK.self::extensionOf($name) : $ascii;
    }

    private static function extensionOf(string $name): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        return preg_match('/^[A-Za-z0-9]{1,15}$/', $extension) === 1 ? '.'.$extension : '';
    }
}
