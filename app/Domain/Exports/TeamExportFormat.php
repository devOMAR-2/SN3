<?php

namespace App\Domain\Exports;

use App\Domain\Exports\Sections\MembersSection;
use App\Domain\Exports\Sections\ProjectMembersSection;
use App\Domain\Exports\Sections\ProjectsSection;
use App\Domain\Exports\Sections\StatusesSection;
use App\Domain\Exports\Sections\TeamSection;

/**
 * The versioned team export format (DATABASE.md §13 "versioned JSON manifests", ADR-024 §2).
 *
 * A zip archive with `manifest.json` and one `data/<section>.json` file per section, in this order.
 * Later tasks append their sections here (CORE-005: tasks; CORE-007: comments, checklist items,
 * attachment manifests; …). Appending a section or a field is backwards compatible and keeps
 * VERSION; anything else bumps it.
 */
final class TeamExportFormat
{
    public const NAME = 'sn3.team-export';

    public const VERSION = 1;

    /**
     * @var list<class-string<TeamExportSection>>
     */
    public const SECTIONS = [
        TeamSection::class,
        MembersSection::class,
        ProjectsSection::class,
        ProjectMembersSection::class,
        StatusesSection::class,
    ];
}
