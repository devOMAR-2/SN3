<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Projects\ProjectWriteLock;
use App\Models\Label;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Removes a label and its associations (API_SPEC.md §5 DELETE /labels/{label}; ADR-023 §6).
 *
 * Under the team → project → label locks, the tasks that carried the label (trashed ones too) get a
 * new `version`, because their `label_ids` change; then the label row goes, and its `task_labels`
 * rows go with it by cascade.
 */
final class DeleteLabel
{
    public function handle(Project $project, Label $label, User $actor): void
    {
        DB::transaction(function () use ($project, $label, $actor): void {
            $locked = ProjectWriteLock::project($project, $actor, 'manageLabels');

            $row = $locked->labels()->whereKey($label->id)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(Label::class, [$label->id]);

            $tasks = DB::update(
                'UPDATE tasks SET version = version + 1, updated_at = now() '
                .'WHERE project_id = ? AND id IN (SELECT task_id FROM task_labels WHERE label_id = ?)',
                [$locked->id, $row->id],
            );

            $row->delete();

            Log::info('label.deleted', [
                'team_id' => $locked->team_id,
                'project_id' => $locked->id,
                'label_id' => $row->id,
                'actor_id' => $actor->id,
                'tasks' => $tasks,
            ]);
        });
    }
}
