<?php

namespace App\Domain\Trash;

use App\Models\Project;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hard-deletes teams and projects that have been in the trash for 30 days (DECIDED-011, acceptance
 * scenario DEL-02, ADR-022 §5). Run daily by the scheduler (`trash:purge`).
 *
 * - **Descendants go by cascade.** Only the root row (`projects` or `teams`) is deleted; every table
 *   below it references its project or team with ON DELETE CASCADE (memberships today; statuses,
 *   tasks, comments, attachments metadata, queued integration links later), so new descendant tables
 *   are purged without touching this class. TrashPurgeTest guards that rule.
 * - **Deferred keys.** Each delete runs in a transaction with `SET CONSTRAINTS ALL DEFERRED`. Keys
 *   between two descendants, such as `project_memberships_team_id_user_id_foreign` (project
 *   memberships → team memberships, ADR-020 §2), are otherwise checked while one cascade branch has
 *   run and the other has not, and refuse the purge. Deferred, they are checked at COMMIT, when the
 *   whole subtree is gone. Only DEFERRABLE keys are affected; later keys between descendants without
 *   a cascade must be DEFERRABLE for the same reason.
 * - **Batched and idempotent.** Candidates are read in keyset batches of BATCH rows, and each row is
 *   deleted in its own short transaction, after locking its team row (the lock order of every
 *   writer, ADR-018 §4). The delete repeats the trash condition, so a row restored after it was
 *   selected survives (the restore holds the row lock; PostgreSQL re-evaluates the condition once
 *   it is released). Running the purge again, or concurrently, deletes nothing twice.
 * - **Never remote.** The purge only deletes local rows. It never calls GitHub or any other external
 *   service (AGENTS.md §2, DECIDED-005, DECIDED-011): remote repositories, Issues and pull requests
 *   linked to a purged project stay untouched.
 *
 * Projects are purged first, then teams (which take their remaining projects with them). Each purge
 * is logged with IDs only.
 */
final class PurgeTrash
{
    public const BATCH = 100;

    /**
     * @return array{projects: int, teams: int}
     */
    public function handle(?CarbonInterface $now = null): array
    {
        $cutoff = TrashRetention::cutoff($now);

        return [
            'projects' => $this->purgeProjects($cutoff),
            'teams' => $this->purgeTeams($cutoff),
        ];
    }

    private function purgeProjects(CarbonInterface $cutoff): int
    {
        $purged = 0;
        $after = null;

        do {
            $candidates = Project::onlyTrashed()->where('deleted_at', '<=', $cutoff)->orderBy('id')->limit(self::BATCH);

            if ($after !== null) {
                $candidates->where('id', '>', $after);
            }

            $batch = $candidates->get(['id', 'team_id']);

            foreach ($batch as $project) {
                $after = $project->id;

                $deleted = DB::transaction(function () use ($project, $cutoff): int {
                    DB::statement('SET CONSTRAINTS ALL DEFERRED');

                    Team::withTrashed()->whereKey($project->team_id)->lock(Team::WRITE_LOCK)->first();

                    return DB::table('projects')
                        ->where('id', $project->id)
                        ->whereNotNull('deleted_at')
                        ->where('deleted_at', '<=', $cutoff)
                        ->delete();
                });

                if ($deleted > 0) {
                    $purged++;
                    Log::info('trash.project_purged', ['team_id' => $project->team_id, 'project_id' => $project->id]);
                }
            }
        } while ($batch->count() === self::BATCH);

        return $purged;
    }

    private function purgeTeams(CarbonInterface $cutoff): int
    {
        $purged = 0;
        $after = null;

        do {
            $candidates = Team::onlyTrashed()->where('deleted_at', '<=', $cutoff)->orderBy('id')->limit(self::BATCH);

            if ($after !== null) {
                $candidates->where('id', '>', $after);
            }

            $batch = $candidates->get(['id']);

            foreach ($batch as $team) {
                $teamId = $team->id;
                $after = $teamId;

                $deleted = DB::transaction(function () use ($teamId, $cutoff): int {
                    // Team memberships and projects are both cascades of the team; without deferring,
                    // project_memberships_team_id_user_id_foreign is checked in between (ADR-020 §2).
                    DB::statement('SET CONSTRAINTS ALL DEFERRED');

                    return DB::table('teams')
                        ->where('id', $teamId)
                        ->whereNotNull('deleted_at')
                        ->where('deleted_at', '<=', $cutoff)
                        ->delete();
                });

                if ($deleted > 0) {
                    $purged++;
                    Log::info('trash.team_purged', ['team_id' => $teamId]);
                }
            }
        } while ($batch->count() === self::BATCH);

        return $purged;
    }
}
