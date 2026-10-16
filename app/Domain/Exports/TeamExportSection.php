<?php

namespace App\Domain\Exports;

/**
 * One section of the team export format (ADR-024 §2): a JSON array of records written to
 * `data/<name>.json` and listed in `manifest.json`.
 *
 * Adding a section (tasks in CORE-005, comments, attachments manifests…) means writing a class
 * that implements this interface and appending it to TeamExportFormat::SECTIONS. Rules:
 *
 * - **Scope through the context.** Read only rows of `$context->team`, and for anything below a
 *   project only rows of `$context->projectIds()` (the projects the requesting Owner can see,
 *   resolved through AccessibleProjects when the job runs). Never resolve projects any other way.
 * - **Allowlist fields.** Build each record from named columns; never serialise a model wholesale.
 *   Secrets (password hashes, remember/session/API/installation tokens, webhook secrets, signed
 *   URLs) never appear. TeamExportTest checks every section for them.
 * - **Stable output.** Order by a unique key (`id`, UUIDv7) and stream (`lazyById`) so memory stays
 *   flat. Timestamps are RFC 3339 UTC strings, as in the API.
 * - **Additive changes only.** New sections and new fields keep `format_version`; renaming or
 *   removing a field, or changing its meaning, needs a new version (TeamExportFormat::VERSION).
 */
interface TeamExportSection
{
    /** Lower-case snake_case and unique; the file is `data/<name>.json`. */
    public function name(): string;

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function records(TeamExportContext $context): iterable;
}
