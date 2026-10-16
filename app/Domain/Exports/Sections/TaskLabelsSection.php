<?php

namespace App\Domain\Exports\Sections;

use App\Domain\Exports\TeamExportContext;
use App\Domain\Exports\TeamExportSection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * `data/task_labels.json`: which label each exported task carries (CORE-005). Only links of live
 * tasks of the exported projects, so every link points at a task in `tasks.json` and a label in
 * `labels.json`. The table has no ID; records are ordered by `(task_id, label_id)`, its primary key.
 */
final class TaskLabelsSection implements TeamExportSection
{
    public function name(): string
    {
        return 'task_labels';
    }

    public function records(TeamExportContext $context): iterable
    {
        $links = DB::table('task_labels')
            ->join('tasks', 'tasks.id', '=', 'task_labels.task_id')
            ->where('tasks.team_id', $context->team->id)
            ->whereIn('task_labels.project_id', $context->projectIds())
            ->whereNull('tasks.deleted_at')
            ->orderBy('task_labels.task_id')
            ->orderBy('task_labels.label_id')
            ->select(['task_labels.task_id', 'task_labels.label_id', 'task_labels.created_at'])
            ->lazy(500);

        foreach ($links as $link) {
            if (! $link instanceof stdClass) {
                continue;
            }

            yield [
                'task_id' => $link->task_id,
                'label_id' => $link->label_id,
                'created_at' => is_string($link->created_at) ? TeamExportContext::timestamp(Carbon::parse($link->created_at)) : null,
            ];
        }
    }
}
