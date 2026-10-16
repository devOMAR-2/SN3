<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Projects\ProjectWriteLock;
use App\Models\Label;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Renames or recolours a label (API_SPEC.md §5 PATCH /labels/{label}; ADR-023 §6). Labels are not
 * among the versioned resources of §1.4, so there is no `If-Match`; concurrent edits are serialised
 * by the team → project → label locks. A label removed meanwhile is a 404; a taken name a 409.
 *
 * Tasks show label IDs only, so a rename does not change any task's representation or version.
 */
final class UpdateLabel
{
    /**
     * @param  array{name?: string, color?: string|null}  $changes
     */
    public function handle(Project $project, Label $label, User $actor, array $changes): Label
    {
        try {
            return DB::transaction(function () use ($project, $label, $actor, $changes): Label {
                $locked = ProjectWriteLock::project($project, $actor, 'manageLabels');

                $row = $locked->labels()->whereKey($label->id)->lockForUpdate()->first()
                    ?? throw (new ModelNotFoundException)->setModel(Label::class, [$label->id]);

                $row->fill($changes);

                if ($row->isDirty()) {
                    $row->save();

                    Log::info('label.updated', [
                        'team_id' => $locked->team_id,
                        'project_id' => $locked->id,
                        'label_id' => $row->id,
                        'actor_id' => $actor->id,
                    ]);
                }

                return $row;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw CreateLabel::duplicateName($e);
        }
    }
}
