<?php

use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Domain\Trash\PurgeTrash;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// CORE-010: the trash purge through real COMMITs (ADR-022 §5). Deferred constraints and deferred
// triggers only fire at COMMIT, which RefreshDatabase's wrapping transaction never reaches, so this
// file works on committed data and cleans up after itself, like LifecycleConcurrencyTest.
//
// - A team with project memberships is purged for real: the deferred
//   project_memberships_team_id_user_id_foreign check passes at COMMIT because the whole subtree is gone.
// - A row whose delete fails at COMMIT is skipped and logged, the other rows are still purged, and
//   `trash:purge` exits non-zero.

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);

    $this->teams = [];
    $this->users = [];
});

afterEach(function () {
    DB::unprepared(<<<'SQL'
        DROP TRIGGER IF EXISTS teams_purge_probe ON teams;
        DROP FUNCTION IF EXISTS sn3_test_refuse_team_purge();
        SQL);

    $teamIds = array_map(fn (Team $team) => $team->id, $this->teams);
    $userIds = TeamMembership::query()->whereIn('team_id', $teamIds)->pluck('user_id')->merge($this->users)->unique()->all();

    DB::transaction(function () use ($teamIds): void {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::table('teams')->whereIn('id', $teamIds)->delete();
    });
    User::query()->whereKey($userIds)->delete();
});

/**
 * A committed team, with a private project, a Project Manager and a contributor, trashed 31 days ago.
 */
function committedTrashedTeam(object $test): Team
{
    $team = Team::factory()->create();
    $test->teams[] = $team;
    $test->users[] = $team->owner_user_id;

    $project = Project::factory()->for($team)->private()->create();
    $manager = TeamMembership::factory()->for($team)->role(TeamRole::Member)->create()->user;
    ProjectMembership::factory()->for($project)->member($manager)->role(ProjectRole::Manager)->create();
    ProjectMembership::factory()->for($project)->member($team->owner)->create();
    $test->users[] = $manager->id;

    $team->forceFill(['deleted_at' => now()->subDays(31)])->save();

    return $team;
}

it('purges a team with project memberships through a real COMMIT', function () {
    $team = committedTrashedTeam($this);
    $projectId = Project::withTrashed()->where('team_id', $team->id)->value('id');

    expect(DB::transactionLevel())->toBe(0)
        ->and(ProjectMembership::query()->where('team_id', $team->id)->count())->toBe(2);

    expect(app(PurgeTrash::class)->handle())->toBe(['projects' => 0, 'teams' => 1, 'failed' => 0]);

    expect(Team::withTrashed()->whereKey($team->id)->exists())->toBeFalse()
        ->and(TeamMembership::query()->where('team_id', $team->id)->exists())->toBeFalse()
        ->and(Project::withTrashed()->whereKey($projectId)->exists())->toBeFalse()
        ->and(ProjectMembership::query()->where('team_id', $team->id)->exists())->toBeFalse();
});

it('skips a row that fails at COMMIT, purges the others and exits non-zero', function () {
    Log::spy();
    // UUIDv7 ids are time-ordered, so the broken team comes first in the purge's id order.
    $broken = committedTrashedTeam($this);
    $healthy = committedTrashedTeam($this);

    // A deferred trigger that refuses one team's delete, at COMMIT only.
    DB::unprepared(sprintf(<<<'SQL'
        CREATE FUNCTION sn3_test_refuse_team_purge() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            IF OLD.id = '%s' THEN
                RAISE EXCEPTION 'refused by test' USING ERRCODE = 'check_violation';
            END IF;
            RETURN NULL;
        END;
        $$;

        CREATE CONSTRAINT TRIGGER teams_purge_probe
            AFTER DELETE ON teams
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION sn3_test_refuse_team_purge();
        SQL, $broken->id));

    $this->artisan('trash:purge')
        ->expectsOutputToContain('Purged 0 project(s) and 1 team(s) from the trash.')
        ->expectsOutputToContain('1 row(s) could not be purged')
        ->assertExitCode(1);

    expect(Team::withTrashed()->whereKey($healthy->id)->exists())->toBeFalse()
        ->and(Team::withTrashed()->whereKey($broken->id)->exists())->toBeTrue()
        // The failed row was rolled back whole: memberships and the project are all still there.
        ->and(TeamMembership::query()->where('team_id', $broken->id)->count())->toBe(2)
        ->and(ProjectMembership::query()->where('team_id', $broken->id)->count())->toBe(2)
        ->and(DB::transactionLevel())->toBe(0);

    Log::shouldHaveReceived('warning')->with('trash.purge_failed', ['type' => 'team', 'id' => $broken->id, 'team_id' => $broken->id])->once();
    Log::shouldHaveReceived('info')->with('trash.team_purged', ['team_id' => $healthy->id])->once();
});
