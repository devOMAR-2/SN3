<?php

namespace App\Notifications;

/**
 * User-written text (names, titles) as inert Markdown for mail lines (CORE-002, ADR-025 §2; reused by
 * CORE-009's notification emails). Mail lines are rendered as Markdown, so a name like
 * `[Verify now](https://evil.example)` or `![x](https://evil.example/p.png)` would otherwise become a
 * live link or a tracking image in a mail from this instance. Line breaks become spaces, and the
 * characters that build links, images, emphasis, headings, code and tables are backslash-escaped
 * (CommonMark §2.4). `& ' " < >` are left to the template's HTML escaping: a backslash before them
 * would show the entity text instead of the character.
 */
final class PlainMarkdown
{
    public static function escape(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        return (string) preg_replace('/([\\\\`*_{}\[\]()#!|~])/', '\\\\$1', $value);
    }
}
