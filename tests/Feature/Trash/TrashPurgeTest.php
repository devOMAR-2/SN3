<?php

use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Domain\Trash\PurgeTrash;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Team;
use App\Models\TeamMembership;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// CORE-010: the scheduled 30-day trash purge (DECIDED-011, ADR-022 §5). Hard-deletes only teams and
// projects trashed at least 30 days ago, with their whole subtree by cascade, in batches and
// idempotently; frees their slugs and keys; never calls GitHub or any other remote service.
// Acceptance scenario DEL-02; TEST-FR-PROJ-008.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();
    $this->team = Team::factory()->create(['slug' => 'website']);
    $this->owner = $this->team->owner;
    $this->member = TeamMembership::factory()->for($this->team)->role(TeamRole::Member)->create()->user;
});

function trashedProject(Team $team, int $daysAgo, array $attributes = []): Project
{
    $project = Project::factory()->for($team)->create($attributes);
    $project->forceFill(['deleted_at' => now()->subDays($daysAgo)])->save();

    return $project;
}

function projectExists(Project $project): bool
{
    return Project::withTrashed()->whereKey($project->id)->exists();
}

it('purges projects trashed 30 days ago or more, with their memberships, and keeps everything else (DEL-02)', function () {
    Log::spy();
    $old = Project::factory()->for($this->team)->create();
    ProjectMembership::factory()->for($old)->member($this->member)->role(ProjectRole::Manager)->create();
    $old->forceFill(['deleted_at' => now()->subDays(31)])->save();
    $exactly = trashedProject($this->team, 30);
    $recent = Project::factory()->for($this->team)->create();
    ProjectMembership::factory()->for($recent)->member($this->member)->create();
    $recent->forceFill(['deleted_at' => now()->subDays(29)])->save();
    $live = Project::factory()->for($this->team)->create();
    $archived = Project::factory()->for($this->team)->archived()->create(['updated_at' => now()->subYear()]);

    $this->artisan('trash:purge')
        ->expectsOutputToContain('Purged 2 project(s) and 0 team(s) from the trash.')
        ->assertSuccessful();

    expect(projectExists($old))->toBeFalse()
        ->and(projectExists($exactly))->toBeFalse()
        ->and(projectExists($recent))->toBeTrue()
        ->and(projectExists($live))->toBeTrue()
        ->and(projectExists($archived))->toBeTrue()
        ->and(ProjectMembership::query()->where('project_id', $old->id)->exists())->toBeFalse()
        ->and(ProjectMembership::query()->where('project_id', $recent->id)->exists())->toBeTrue()
        // The team and its memberships are untouched.
        ->and($this->team->fresh())->not->toBeNull()
        ->and(TeamMembership::query()->where('team_id', $this->team->id)->count())->toBe(2);

    Log::shouldHaveReceived('info')->with('trash.project_purged', ['team_id' => $this->team->id, 'project_id' => $old->id])->once();
});

it('purges a team trashed 30 days ago with its memberships, projects and project memberships', function () {
    Log::spy();
    $project = Project::factory()->for($this->team)->private()->create();
    ProjectMembership::factory()->for($project)->member($this->member)->role(ProjectRole::Manager)->create();
    ProjectMembership::factory()->for($project)->member($this->owner)->create();
    $alsoTrashed = trashedProject($this->team, 2);
    $this->team->forceFill(['deleted_at' => now()->subDays(30)])->save();

    $survivor = Team::factory()->create();
    $survivorProject = Project::factory()->for($survivor)->create();
    $recentTeam = Team::factory()->create();
    $recentTeam->forceFill(['deleted_at' => now()->subDays(29)])->save();

    expect(app(PurgeTrash::class)->handle())->toBe(['projects' => 0, 'teams' => 1]);

    expect(Team::withTrashed()->whereKey($this->team->id)->exists())->toBeFalse()
        ->and(TeamMembership::query()->where('team_id', $this->team->id)->exists())->toBeFalse()
        ->and(projectExists($project))->toBeFalse()
        ->and(projectExists($alsoTrashed))->toBeFalse()
        ->and(ProjectMembership::query()->where('team_id', $this->team->id)->exists())->toBeFalse()
        ->and(DB::table('users')->whereIn('id', [$this->owner->id, $this->member->id])->count())->toBe(2)
        ->and(Team::query()->whereKey($survivor->id)->exists())->toBeTrue()
        ->and(projectExists($survivorProject))->toBeTrue()
        ->and(Team::withTrashed()->whereKey($recentTeam->id)->exists())->toBeTrue();

    Log::shouldHaveReceived('info')->with('trash.team_purged', ['team_id' => $this->team->id])->once();
});

it('needs the deferred membership key: deleting a team row with project memberships is otherwise refused', function () {
    $project = Project::factory()->for($this->team)->create();
    ProjectMembership::factory()->for($project)->member($this->member)->create();
    $this->team->forceFill(['deleted_at' => now()->subDays(40)])->save();

    // What the purge avoids (ADR-020 §2): the immediate check fires between the two cascades.
    $error = null;
    try {
        DB::transaction(fn () => DB::table('teams')->where('id', $this->team->id)->delete());
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    expect($error)->toContain('project_memberships_team_id_user_id_foreign');

    expect(app(PurgeTrash::class)->handle()['teams'])->toBe(1)
        ->and(Team::withTrashed()->whereKey($this->team->id)->exists())->toBeFalse();
});

it('is idempotent: a second run purges nothing', function () {
    trashedProject($this->team, 45);
    $this->team->forceFill(['deleted_at' => now()->subDays(45)])->save();

    expect(app(PurgeTrash::class)->handle())->toBe(['projects' => 1, 'teams' => 1])
        ->and(app(PurgeTrash::class)->handle())->toBe(['projects' => 0, 'teams' => 0]);

    $this->artisan('trash:purge')->expectsOutputToContain('Purged 0 project(s) and 0 team(s)')->assertSuccessful();
});

it('works through more rows than one batch', function () {
    $count = PurgeTrash::BATCH + 7;

    foreach (range(1, $count) as $i) {
        trashedProject($this->team, 31, ['project_key' => 'B'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
    }
    trashedProject($this->team, 5);

    expect(app(PurgeTrash::class)->handle()['projects'])->toBe($count)
        ->and(Project::withTrashed()->where('team_id', $this->team->id)->count())->toBe(1);
});

it('keeps slugs and keys reserved until the purge and frees them afterwards', function () {
    trashedProject($this->team, 10, ['project_key' => 'WEB']);
    $other = Team::factory()->create();
    $other->forceFill(['slug' => 'old-name', 'deleted_at' => now()->subDays(10)])->save();

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => 'Again', 'key' => 'WEB'])->assertStatus(409);
    $this->actingAs($this->owner)->postJson('/api/v1/teams', ['name' => 'Old', 'slug' => 'old-name'])->assertStatus(409);

    $this->travel(21)->days();
    $this->artisan('trash:purge')->assertSuccessful();

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => 'Again', 'key' => 'WEB'])->assertCreated();
    $this->actingAs($this->owner)->postJson('/api/v1/teams', ['name' => 'Old', 'slug' => 'old-name'])->assertCreated();
});

it('never calls out to GitHub or any other remote service', function () {
    Http::preventStrayRequests();
    Http::fake();
    trashedProject($this->team, 31);
    $this->team->forceFill(['deleted_at' => now()->subDays(31)])->save();

    $this->artisan('trash:purge')->assertSuccessful();

    Http::assertNothingSent();
});

it('leaves a recovered project alone even after the 30 days have passed', function () {
    $project = trashedProject($this->team, 1);
    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$project->id}/restore-deleted")->assertOk();

    $this->travel(60)->days();

    expect(app(PurgeTrash::class)->handle()['projects'])->toBe(0)
        ->and(projectExists($project))->toBeTrue();
});

it('re-checks the trash condition when deleting, so a row recovered after it was selected survives', function (string $table) {
    $project = trashedProject($this->team, 31);

    if ($table === 'teams') {
        $this->team->forceFill(['deleted_at' => now()->subDays(31)])->save();
    }

    $id = $table === 'projects' ? $project->id : $this->team->id;
    $recovered = false;

    // Recover the row right after the purge has read its batch of candidates.
    DB::listen(function (QueryExecuted $query) use ($table, $id, &$recovered): void {
        if (! $recovered && str_starts_with($query->sql, 'select') && str_contains($query->sql, "from \"{$table}\"") && str_contains($query->sql, 'order by "id"')) {
            $recovered = true;
            DB::table($table)->where('id', $id)->update(['deleted_at' => null]);
        }
    });

    $purged = app(PurgeTrash::class)->handle();

    expect($recovered)->toBeTrue()
        ->and(DB::table($table)->where('id', $id)->exists())->toBeTrue()
        ->and($purged[$table])->toBe(0)
        // In the team case the (still trashed) project itself is purged first, on its own.
        ->and(projectExists($project))->toBe($table === 'projects');
})->with(['projects', 'teams']);

it('reaches every descendant by cascade: each key to teams or projects cascades, and others between tenant tables are deferrable', function () {
    // Foreign keys whose referencing table belongs to a team (has team_id, or is projects).
    $keys = collect(DB::select(<<<'SQL'
        select c.conname, src.relname as source, dst.relname as target, c.confdeltype as on_delete, c.condeferrable as deferrable
        from pg_constraint c
        join pg_class src on src.oid = c.conrelid
        join pg_class dst on dst.oid = c.confrelid
        join pg_namespace n on n.oid = src.relnamespace
        where c.contype = 'f' and n.nspname = current_schema()
          and exists (select 1 from information_schema.columns col
                      where col.table_schema = current_schema() and col.table_name = src.relname and col.column_name = 'team_id')
        SQL));

    expect($keys)->not->toBeEmpty();

    $tenantTables = collect(DB::select("select table_name from information_schema.columns where table_schema = current_schema() and column_name = 'team_id'"))
        ->pluck('table_name')
        ->push('teams')
        ->all();

    foreach ($keys as $key) {
        if (in_array($key->target, ['teams', 'projects'], true)) {
            // The purge deletes only the root row; anything referencing it must go with it.
            expect($key->on_delete)->toBe('c', "{$key->conname} must be ON DELETE CASCADE so the trash purge reaches it");
        } elseif (in_array($key->target, $tenantTables, true) && $key->on_delete !== 'c') {
            // A key between two descendants without a cascade must be deferrable, or the purge's
            // cascades trip over it (ADR-020 §2, ADR-022 §5).
            expect($key->deferrable)->toBeTrue("{$key->conname} must be DEFERRABLE so the trash purge can defer it");
        }
    }
});
