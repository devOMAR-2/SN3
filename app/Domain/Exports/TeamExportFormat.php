<?php

namespace App\Domain\Exports;

use App\Domain\Exports\Sections\ActivitySection;
use App\Domain\Exports\Sections\AttachmentsSection;
use App\Domain\Exports\Sections\CommentsSection;
use App\Domain\Exports\Sections\LabelsSection;
use App\Domain\Exports\Sections\MembersSection;
use App\Domain\Exports\Sections\ProjectMembersSection;
use App\Domain\Exports\Sections\ProjectsSection;
use App\Domain\Exports\Sections\StatusesSection;
use App\Domain\Exports\Sections\TaskChecklistItemsSection;
use App\Domain\Exports\Sections\TaskLabelsSection;
use App\Domain\Exports\Sections\TasksSection;
use App\Domain\Exports\Sections\TeamSection;

/**
 * The versioned team export format (DATABASE.md §13 "versioned JSON manifests", ADR-024 §2).
 *
 * A zip archive with `manifest.json` and one `data/<section>.json` file per section, in this order.
 * Later tasks append their sections here (CORE-005: labels, tasks, task labels, checklist items;
 * CORE-008: comments, activity, attachments, whose files go under `files/<id>`; …). Appending a
 * section or a field is backwards compatible and keeps VERSION; anything else bumps it.
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
        // CORE-005 (ADR-023 §11): live tasks of the exported projects, their labels and checklists.
        LabelsSection::class,
        TasksSection::class,
        TaskLabelsSection::class,
        TaskChecklistItemsSection::class,
        // CORE-008 (ADR-027 §7): comments, the activity log, and attachments with their files.
        CommentsSection::class,
        ActivitySection::class,
        AttachmentsSection::class,
    ];
}
