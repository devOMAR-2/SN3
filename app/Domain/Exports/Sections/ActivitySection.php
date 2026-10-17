<?php

namespace App\Domain\Exports\Sections;

use App\Domain\Exports\TeamExportContext;
use App\Domain\Exports\TeamExportSection;
use App\Models\TaskActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * `data/activity.json`: the team's audit metadata (DATABASE.md §13 "… sprint history and audit
 * metadata"; CORE-008, ADR-027 §7). Team entries, and the entries of the exported projects, except
 * those of tasks in the task trash (which are left out of the export with their task). `changes` is
 * the stored allowlist of IDs, enum values, dates and field names.
 */
final class ActivitySection implements TeamExportSection
{
    public function name(): string
    {
        return 'activity';
    }

    public function records(TeamExportContext $context): iterable
    {
        $projectIds = $context->projectIds();

        $entries = TaskActivity::query()
            ->where('team_id', $context->team->id)
            ->where(function (Builder $scope) use ($projectIds): void {
                $scope->whereNull('project_id')
                    ->orWhere(function (Builder $project) use ($projectIds): void {
                        $project->whereIn('project_id', $projectIds)
                            ->where(function (Builder $task): void {
                                $task->whereNull('task_id')
                                    ->orWhereExists(fn (QueryBuilder $live) => $live->select(DB::raw(1))->from('tasks')
                                        ->whereColumn('tasks.id', 'task_activities.task_id')
                                        ->whereNull('tasks.deleted_at'));
                            });
                    });
            })
            ->lazyById(1000);

        foreach ($entries as $entry) {
            yield [
                'id' => $entry->id,
                'project_id' => $entry->project_id,
                'task_id' => $entry->task_id,
                'actor_user_id' => $entry->actor_user_id,
                'action' => $entry->action,
                'changes' => $entry->changes === [] ? (object) [] : $entry->changes,
                'source' => $entry->source,
                'created_at' => TeamExportContext::timestamp($entry->created_at),
            ];
        }
    }
}
