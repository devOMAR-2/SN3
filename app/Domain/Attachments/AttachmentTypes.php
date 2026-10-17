<?php

namespace App\Domain\Attachments;

use finfo;

/**
 * Which files may be attached (API_SPEC.md §6.3 "Validate actual MIME/file signature where feasible";
 * CORE-008, ADR-027 §5). The specs name no list, so this is the conservative allowlist of common
 * document, image, media and archive formats.
 *
 * - **Sniffed, never trusted:** the type is read from the file's content (libmagic, `finfo`); the
 *   client's Content-Type is ignored, and the sniffed type is what is stored and served.
 * - **Name and content must agree:** the file name's extension must be one of the extensions listed
 *   for the sniffed type. A Windows executable named `report.pdf`, a PDF named `run.exe` and an HTML
 *   page named `notes.txt` are all refused.
 * - **Never accepted:** anything a browser could run or render actively (HTML, SVG, XML, scripts,
 *   executables), legacy Office files with their macro formats, unknown binaries
 *   (`application/octet-stream`) and empty files.
 * - Polyglots (a GIF that is also JavaScript, a PDF with a zip appended) pass when their sniffed type
 *   and extension agree; downloads are therefore always `attachment`, `nosniff` and sandboxed, so no
 *   browser ever renders or runs one inline. Archives are stored as they are and never unpacked on
 *   the server, so archive bombs cost nothing.
 */
final class AttachmentTypes
{
    /**
     * Sniffed MIME type => allowed file-name extensions.
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED = [
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/heic' => ['heic'],
        'image/heif' => ['heif'],
        'application/pdf' => ['pdf'],
        'text/plain' => ['txt', 'text', 'md', 'markdown', 'log', 'csv', 'tsv'],
        'text/csv' => ['csv'],
        'application/json' => ['json'],
        // Office Open XML and OpenDocument files are zip containers; libmagic names them by their
        // content when it recognises it, and `application/zip` otherwise.
        'application/zip' => ['zip', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/vnd.oasis.opendocument.text' => ['odt'],
        'application/vnd.oasis.opendocument.spreadsheet' => ['ods'],
        'application/vnd.oasis.opendocument.presentation' => ['odp'],
        'application/gzip' => ['gz', 'tgz'],
        'application/x-tar' => ['tar'],
        'video/mp4' => ['mp4', 'm4v'],
        'video/quicktime' => ['mov'],
        'video/webm' => ['webm'],
        'audio/mpeg' => ['mp3'],
        'audio/ogg' => ['ogg', 'oga'],
        'audio/x-wav' => ['wav'],
        'audio/wav' => ['wav'],
    ];

    /** The MIME type read from the file's content. */
    public static function sniff(string $path): ?string
    {
        $type = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($type) && $type !== '' ? strtolower($type) : null;
    }

    /** Whether a file of this sniffed type may carry this name. */
    public static function allows(?string $sniffed, string $filename): bool
    {
        if ($sniffed === null || ! array_key_exists($sniffed, self::ALLOWED)) {
            return false;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::ALLOWED[$sniffed], true);
    }
}
