<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Projects\ProjectWriteLock;
use App\Models\Label;
use App\Models\Project;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates a project label (API_SPEC.md §5 POST /labels, "Owner/Project Manager"; ADR-023 §6).
 *
 * Locks team → project (`manageLabels`): 423 on an archived team or project, 404 / 409 when the
 * actor's access changed meanwhile. A name already used in the project, ignoring case, is a 409, and
 * so is a label beyond the per-project cap.
 */
final class CreateLabel
{
    /** At most this many labels per project (ADR-023 §6). */
    public const MAX_PER_PROJECT = 100;

    public function handle(Project $project, User $actor, string $name, ?string $color): Label
    {
        try {
            return DB::transaction(function () use ($project, $actor, $name, $color): Label {
                $locked = ProjectWriteLock::project($project, $actor, 'manageLabels');

                if ($locked->labels()->count() >= self::MAX_PER_PROJECT) {
                    throw new DomainConflictException('A project holds at most '.self::MAX_PER_PROJECT.' labels.');
                }

                $label = new Label;
                $label->forceFill([
                    'team_id' => $locked->team_id,
                    'project_id' => $locked->id,
                    'name' => $name,
                    'color' => $color,
                ])->save();

                Log::info('label.created', [
                    'team_id' => $locked->team_id,
                    'project_id' => $locked->id,
                    'label_id' => $label->id,
                    'actor_id' => $actor->id,
                ]);

                return $label;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw self::duplicateName($e);
        }
    }

    /**
     * A taken name is a 409; any other unique violation is not expected and propagates.
     */
    public static function duplicateName(UniqueConstraintViolationException $e): Throwable
    {
        return str_contains($e->getMessage(), 'labels_project_id_lower_name_unique')
            ? new DomainConflictException('A label with this name already exists in the project.', previous: $e)
            : $e;
    }
}
