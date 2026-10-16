<?php

use App\Domain\Exports\Actions\RequestTeamExport;
use App\Domain\Exports\Sections\StatusesSection;
use App\Domain\Exports\TeamExportContext;
use App\Domain\Exports\TeamExportFormat;
use App\Domain\Exports\TeamExportSection;
use App\Domain\Exports\TeamExportStatus;
use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Http\Resources\TeamExportResource;
use App\Jobs\BuildTeamExport;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Team;
use App\Models\TeamExport;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Tenancy\AccessibleProjects;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

// CORE-012: asynchronous, Owner-only team export (API_SPEC.md §3, §13, §18; DATABASE.md §13, §20;
// DECIDED-003, DECIDED-004, DECIDED-011; ADR-024). Acceptance scenarios EXP-01, EXP-02, AUTH-02
// (export), AUTH-04; DATABASE.md §16 DB-T01, DB-T02.

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('exports');
    $this->workRoot = sys_get_temp_dir().'/sn3-export-work-test-'.Str::random(8);
    config(['sn3.exports.work_dir' => $this->workRoot]);

    $this->team = Team::factory()->create(['name' => 'فريق الموقع', 'slug' => 'website']);
    $this->owner = $this->team->owner;
    $this->members = [];

    foreach ([TeamRole::Admin, TeamRole::Member, TeamRole::Viewer] as $role) {
        $this->members[$role->value] = TeamMembership::factory()->for($this->team)->role($role)->create()->user;
    }

    $this->public = Project::factory()->for($this->team)->withDefaultStatuses()->create(['name' => 'Roadmap', 'project_key' => 'WEB']);
    // Private, and the Owner has no membership row: still exported (DECIDED-003 Owner override).
    $this->private = Project::factory()->for($this->team)->private()->withDefaultStatuses('ar')->create(['name' => 'Secret plan', 'project_key' => 'SEC']);
    ProjectMembership::factory()->for($this->private)->member($this->members['member'])->role(ProjectRole::Manager)->create();

    $this->otherTeam = Team::factory()->create();
    $this->outsider = $this->otherTeam->owner;
    $this->foreign = Project::factory()->for($this->otherTeam)->withDefaultStatuses()->create(['name' => 'Foreign project']);
});

afterEach(function () {
    File::deleteDirectory($this->workRoot);
});

dataset('non-owner team roles', ['admin', 'member', 'viewer']);

function requestExport(User $user, Team $team, array $body = [], ?string $key = null)
{
    return test()->actingAs($user)->postJson("/api/v1/teams/{$team->id}/exports", $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
}

/**
 * @return array<string, string> entry name => contents
 */
function exportEntries(string $zip): array
{
    $path = tempnam(sys_get_temp_dir(), 'sn3-test-export');
    file_put_contents($path, $zip);
    $archive = new ZipArchive;
    expect($archive->open($path))->toBeTrue();

    $entries = [];
    for ($i = 0; $i < $archive->numFiles; $i++) {
        $name = $archive->getNameIndex($i);
        $entries[$name] = $archive->getFromIndex($i);
    }

    $archive->close();
    unlink($path);

    return $entries;
}

function downloadUrlFor(string $id): string
{
    return TeamExportResource::downloadUrl(TeamExport::query()->findOrFail($id));
}

function downloadExport(User $user, string $url): array
{
    $response = test()->actingAs($user)->get($url)->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/zip')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment');

    return exportEntries($response->streamedContent());
}

// --- The happy path -----------------------------------------------------------------------------

it('exports the team asynchronously for its Owner: 202, then a ready export with a signed download', function () {
    Log::spy();

    $response = requestExport($this->owner, $this->team)
        ->assertStatus(202)
        ->assertJsonPath('data.team_id', $this->team->id)
        ->assertJsonPath('data.requested_by', $this->owner->id)
        ->assertJsonPath('data.format', 'sn3.team-export')
        ->assertJsonPath('data.format_version', 1)
        ->assertJsonPath('data.download_url', null);

    $id = $response->json('data.id');
    expect(Str::isUuid($id))->toBeTrue()
        ->and($response->json('data.status'))->toBeIn(['pending', 'processing', 'ready'])
        ->and($response->headers->get('Location'))->toBe("/api/v1/teams/{$this->team->id}/exports/{$id}");

    // The sync queue already ran the job.
    $show = $this->actingAs($this->owner)->getJson("/api/v1/teams/{$this->team->id}/exports/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'ready');

    $export = TeamExport::query()->sole();
    expect($export->status)->toBe(TeamExportStatus::Ready)
        ->and($export->storage_key)->toBe("archives/{$this->team->id}/{$id}.zip")
        ->and($export->expires_at->between(now()->addHours(23)->addMinutes(59), now()->addHours(24)))->toBeTrue()
        ->and($show->json('data.size_bytes'))->toBe($export->size_bytes)
        ->and($show->json('data.sha256'))->toBe(hash('sha256', Storage::disk('exports')->get($export->storage_key)))
        ->and($show->json('data.expires_at'))->toBe($export->expires_at->utc()->toIso8601ZuluString());

    $url = $show->json('data.download_url');
    expect($url)->toContain("/api/v1/teams/{$this->team->id}/exports/{$id}/download?expires=")->toContain('signature=');

    $entries = downloadExport($this->owner, $url);
    expect(array_keys($entries))->toBe([
        'manifest.json', 'data/team.json', 'data/members.json', 'data/projects.json', 'data/project_members.json', 'data/statuses.json',
    ]);

    $context = ['team_id' => $this->team->id, 'export_id' => $id, 'actor_id' => $this->owner->id];
    Log::shouldHaveReceived('info')->with('team_export.requested', $context)->once();
    Log::shouldHaveReceived('info')->with('team_export.completed', [...$context, 'size_bytes' => $export->size_bytes])->once();
    Log::shouldHaveReceived('info')->with('team_export.downloaded', $context)->once();
});

it('writes a versioned manifest and one JSON array per section, with counts and checksums', function () {
    $id = requestExport($this->owner, $this->team)->assertStatus(202)->json('data.id');
    $entries = downloadExport($this->owner, downloadUrlFor($id));

    $manifest = json_decode($entries['manifest.json'], true, flags: JSON_THROW_ON_ERROR);
    expect($manifest)->toMatchArray([
        'format' => TeamExportFormat::NAME,
        'format_version' => TeamExportFormat::VERSION,
        'export_id' => $id,
        'team_id' => $this->team->id,
        'requested_by' => $this->owner->id,
    ])->and($manifest['generated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    expect(array_column($manifest['sections'], 'name'))->toBe(['team', 'members', 'projects', 'project_members', 'statuses']);

    foreach ($manifest['sections'] as $section) {
        $content = $entries[$section['file']];
        expect($section['file'])->toBe("data/{$section['name']}.json")
            ->and(hash('sha256', $content))->toBe($section['sha256'])
            ->and(json_decode($content, true, flags: JSON_THROW_ON_ERROR))->toBeArray()->toHaveCount($section['records']);
    }

    $data = fn (string $name) => json_decode($entries["data/{$name}.json"], true, flags: JSON_THROW_ON_ERROR);

    expect($data('team'))->toBe([[
        'id' => $this->team->id,
        'name' => 'فريق الموقع',
        'slug' => 'website',
        'description' => null,
        'owner_user_id' => $this->owner->id,
        'archived_at' => null,
        'created_at' => $this->team->created_at->utc()->toIso8601ZuluString(),
        'updated_at' => $this->team->updated_at->utc()->toIso8601ZuluString(),
    ]]);

    // Unicode is kept as is (DB-T13).
    expect($entries['data/team.json'])->toContain('فريق الموقع');

    $members = collect($data('members'));
    expect($members)->toHaveCount(4)
        ->and($members->firstWhere('user_id', $this->owner->id))->toBe([
            'user_id' => $this->owner->id,
            'name' => $this->owner->name,
            'email' => $this->owner->email,
            'role' => 'owner',
            'joined_at' => TeamMembership::query()->where('user_id', $this->owner->id)->sole()->joined_at->utc()->toIso8601ZuluString(),
        ])
        ->and($members->pluck('role')->sort()->values()->all())->toBe(['admin', 'member', 'owner', 'viewer']);

    $projects = collect($data('projects'));
    expect($projects->pluck('id')->all())->toEqualCanonicalizing([$this->public->id, $this->private->id])
        ->and($projects->firstWhere('id', $this->private->id))->toMatchArray(['name' => 'Secret plan', 'key' => 'SEC', 'visibility' => 'private'])
        ->and(array_keys($projects->first()))->toBe(['id', 'name', 'key', 'description', 'visibility', 'sprints_enabled', 'created_by', 'archived_at', 'created_at', 'updated_at']);

    expect($data('project_members'))->toBe([[
        'project_id' => $this->private->id,
        'user_id' => $this->members['member']->id,
        'project_role' => 'manager',
        'added_by' => $this->owner->id,
        'created_at' => ProjectMembership::query()->sole()->created_at->utc()->toIso8601ZuluString(),
    ]]);

    $statuses = collect($data('statuses'));
    expect($statuses)->toHaveCount(6)
        ->and($statuses->pluck('project_id')->unique()->values()->all())->toEqualCanonicalizing([$this->public->id, $this->private->id])
        ->and($statuses->where('project_id', $this->private->id)->pluck('name')->all())->toContain('للإنجاز')
        ->and($statuses->firstWhere('is_default', true))->toHaveKeys(['id', 'name', 'category', 'position', 'color']);
});

it('never includes another team, trashed projects or anything secret (DB-T01, DATABASE.md §13)', function () {
    $trashed = Project::factory()->for($this->team)->create(['name' => 'Binned project', 'deleted_at' => now()->subDay()]);
    $this->owner->forceFill(['remember_token' => 'remember-'.Str::random(40)])->save();
    DB::table('sessions')->insert(['id' => 'session-'.Str::random(30), 'user_id' => $this->owner->id, 'payload' => 'payload-secret', 'last_activity' => time()]);

    $id = requestExport($this->owner, $this->team)->assertStatus(202)->json('data.id');
    $entries = downloadExport($this->owner, downloadUrlFor($id));
    $all = implode("\n", $entries);

    foreach ([$this->otherTeam->id, $this->outsider->id, $this->outsider->email, $this->foreign->id, 'Foreign project', $trashed->id, 'Binned project'] as $absent) {
        expect($all)->not->toContain($absent);
    }

    $secrets = [$this->owner->fresh()->password, $this->owner->fresh()->remember_token, 'payload-secret', 'session-'];
    foreach ($this->members as $member) {
        $secrets[] = $member->fresh()->password;
    }

    foreach ($secrets as $secret) {
        expect($all)->not->toContain($secret);
    }

    // No field anywhere is named like a credential.
    $keys = [];
    $decoded = array_map(fn (string $json) => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $entries);
    $collect = function (mixed $value) use (&$collect, &$keys): void {
        if (is_array($value)) {
            foreach ($value as $key => $inner) {
                if (is_string($key)) {
                    $keys[] = $key;
                }
                $collect($inner);
            }
        }
    };
    $collect($decoded);

    expect(collect($keys)->filter(fn (string $key) => preg_match('/password|token|secret|session|signature|remember/i', $key) === 1)->all())->toBe([]);
});

it('exports an archived team: an export reads and changes nothing (ADR-024 §3)', function () {
    $this->team->forceFill(['archived_at' => now()])->save();

    $id = requestExport($this->owner, $this->team)->assertStatus(202)->json('data.id');

    expect(TeamExport::query()->findOrFail($id)->status)->toBe(TeamExportStatus::Ready);
});

// --- Who may export -----------------------------------------------------------------------------

it('denies export, status and download to everyone but the Owner, whatever the body (AUTH-04, AUTH-02)', function (string $role, array $body) {
    Queue::fake();
    $actor = $this->members[$role];

    requestExport($actor, $this->team, $body)->assertForbidden()->assertJsonPath('error.code', 'forbidden');

    // The Owner's export exists, but a member learns nothing about it: 403 before any lookup.
    $export = requestExport($this->owner, $this->team)->assertStatus(202)->json('data.id');
    $this->actingAs($actor)->getJson("/api/v1/teams/{$this->team->id}/exports/{$export}")->assertForbidden();
    $this->actingAs($actor)->getJson("/api/v1/teams/{$this->team->id}/exports/".Str::uuid7())->assertForbidden();

    expect(TeamExport::query()->count())->toBe(1);
    Queue::assertPushed(BuildTeamExport::class, 1);
})->with('non-owner team roles')->with([
    'no body' => [[]],
    'junk body' => [['junk' => 1]],
]);

it('answers 404 to non-members and for random or malformed team IDs (DB-T01)', function (string $id) {
    $id = $id === 'team' ? $this->team->id : $id;

    $this->actingAs($this->outsider)->postJson("/api/v1/teams/{$id}/exports", [], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
    $this->actingAs($this->outsider)->getJson("/api/v1/teams/{$id}/exports/".Str::uuid7())->assertNotFound();

    expect(TeamExport::query()->count())->toBe(0);
})->with(['team', (string) Str::uuid7(), 'not-a-uuid']);

it('answers 404 for an export of another team, another requester, or a malformed ID', function () {
    Queue::fake();
    $foreignExport = requestExport($this->outsider, $this->otherTeam)->assertStatus(202)->json('data.id');

    $this->actingAs($this->owner)->getJson("/api/v1/teams/{$this->team->id}/exports/{$foreignExport}")->assertNotFound();
    $this->actingAs($this->outsider)->getJson("/api/v1/teams/{$this->team->id}/exports/{$foreignExport}")->assertNotFound();
    $this->actingAs($this->owner)->getJson("/api/v1/teams/{$this->team->id}/exports/not-a-uuid")->assertNotFound();
});

it('scopes an export to its own team, even for an Owner of both teams (DB-T01)', function () {
    $teamB = Team::factory()->ownedBy($this->owner)->create();
    $exportB = requestExport($this->owner, $teamB)->assertStatus(202)->json('data.id');

    // Team A's URL with team B's export: the same 404 as a missing export.
    $this->actingAs($this->owner)->getJson("/api/v1/teams/{$this->team->id}/exports/{$exportB}")->assertNotFound();
    $crossSigned = URL::temporarySignedRoute('api.teams.exports.download', now()->addHour(), ['team' => $this->team->id, 'export' => $exportB]);
    $this->actingAs($this->owner)->getJson($crossSigned)->assertNotFound();

    // Through its own team it works.
    $this->actingAs($this->owner)->getJson("/api/v1/teams/{$teamB->id}/exports/{$exportB}")->assertOk()->assertJsonPath('data.team_id', $teamB->id);
    $this->actingAs($this->owner)->get(downloadUrlFor($exportB))->assertOk();
});

it('requires authentication', function () {
    $this->postJson("/api/v1/teams/{$this->team->id}/exports", [], ['Idempotency-Key' => (string) Str::uuid()])->assertUnauthorized();
    $this->getJson("/api/v1/teams/{$this->team->id}/exports/".Str::uuid7())->assertUnauthorized();
});

it('rejects body fields', function () {
    requestExport($this->owner, $this->team, ['format' => 'csv'])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.format.0', 'unknown_field');

    expect(TeamExport::query()->count())->toBe(0);
});

it('answers 404 for a team in the trash', function () {
    $this->team->delete();

    requestExport($this->owner, $this->team)->assertNotFound();
    expect(TeamExport::query()->count())->toBe(0);
});

it('re-checks membership and ownership on the locked team row', function () {
    Queue::fake();
    $admin = $this->members['admin'];

    // A former Owner (after a transfer) still sees the team but lost the right: 409.
    $this->team->memberships()->where('user_id', $this->owner->id)->update(['role' => TeamRole::Admin->value]);
    $this->team->memberships()->where('user_id', $admin->id)->update(['role' => TeamRole::Owner->value]);
    $this->team->forceFill(['owner_user_id' => $admin->id])->save();

    expect(fn () => app(RequestTeamExport::class)->handle($this->team, $this->owner))->toThrow(DomainConflictException::class)
        ->and(fn () => app(RequestTeamExport::class)->handle($this->team, $this->outsider))->toThrow(ModelNotFoundException::class);

    $this->team->delete();
    expect(fn () => app(RequestTeamExport::class)->handle($this->team, $admin))->toThrow(ModelNotFoundException::class)
        ->and(TeamExport::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

// --- Idempotency, safe GET, one export in flight, rate limit ------------------------------------

it('requires an Idempotency-Key and replays the same export for the same key (EXP-02)', function () {
    Queue::fake();

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/exports")->assertStatus(400)->assertJsonPath('error.code', 'bad_request');

    $key = (string) Str::uuid();
    $first = requestExport($this->owner, $this->team, key: $key)->assertStatus(202);
    $replay = requestExport($this->owner, $this->team, key: $key)->assertStatus(202)->assertHeader('Idempotent-Replayed', 'true');

    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and(TeamExport::query()->count())->toBe(1);
    Queue::assertPushed(BuildTeamExport::class, 1);
    Queue::assertPushed(BuildTeamExport::class, fn (BuildTeamExport $job) => $job->exportId === $first->json('data.id'));
});

it('never starts a job from GET (EXP-01)', function () {
    Queue::fake();
    $id = requestExport($this->owner, $this->team)->assertStatus(202)->json('data.id');

    $this->actingAs($this->owner)->getJson("/api/v1/teams/{$this->team->id}/exports/{$id}")->assertOk()->assertJsonPath('data.status', 'pending');
    $this->actingAs($this->owner)->getJson("/api/v1/teams/{$this->team->id}/exports/{$id}")->assertOk()->assertJsonPath('data.download_url', null);

    Queue::assertPushed(BuildTeamExport::class, 1);
    expect(TeamExport::query()->count())->toBe(1);
});

it('allows one export in flight per team (409), and a new one once it has finished', function () {
    Queue::fake();
    $first = requestExport($this->owner, $this->team)->assertStatus(202)->json('data.id');

    requestExport($this->owner, $this->team)->assertConflict()->assertJsonPath('error.code', 'conflict');
    TeamExport::query()->whereKey($first)->update(['status' => 'processing']);
    requestExport($this->owner, $this->team)->assertConflict();

    // Another team is independent.
    requestExport($this->outsider, $this->otherTeam)->assertStatus(202);

    TeamExport::query()->whereKey($first)->update(['status' => 'failed']);
    requestExport($this->owner, $this->team)->assertStatus(202);

    expect(TeamExport::query()->where('team_id', $this->team->id)->count())->toBe(2);
});

it('rate-limits export requests per user', function () {
    Queue::fake();
    config(['sn3.rate_limits.team_exports_per_hour' => 2]);

    requestExport($this->owner, $this->team)->assertStatus(202);
    requestExport($this->owner, $this->team)->assertConflict();
    requestExport($this->owner, $this->team)->assertStatus(429)->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After');
});

// --- Downloads ----------------------------------------------------------------------------------

it('serves the download only to the requesting Owner, with a valid signature', function () {
    $id = requestExport($this->owner, $this->team)->assertStatus(202)->json('data.id');
    $url = downloadUrlFor($id);

    // A leaked link is useless to anyone else: other members 403, other teams 404, guests 401.
    $this->actingAs($this->members['admin'])->get($url)->assertForbidden();
    $this->actingAs($this->outsider)->get($url)->assertNotFound();
    auth()->logout();
    $this->getJson($url)->assertUnauthorized();

    // Tampered or unsigned links: 403.
    $this->actingAs($this->owner)->getJson(str_replace('signature=', 'signature=0', $url))->assertForbidden();
    $this->actingAs($this->owner)->getJson("/api/v1/teams/{$this->team->id}/exports/{$id}/download")->assertForbidden();
    // A signature for one export does not open another.
    $this->actingAs($this->owner)->getJson(str_replace($id, (string) Str::uuid7(), $url))->assertForbidden();

    $this->actingAs($this->owner)->get($url)->assertOk();
});

it('stops serving an export after an ownership transfer, to the old and the new Owner', function () {
    $id = requestExport($this->owner, $this->team)->assertStatus(202)->json('data.id');
    $url = downloadUrlFor($id);
    $admin = $this->members['admin'];

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/ownership-transfer", ['new_owner_user_id' => $admin->id])->assertOk();

    $this->actingAs($this->owner)->get($url)->assertForbidden();
    $this->actingAs($this->owner)->getJson("/api/v1/teams/{$this->team->id}/exports/{$id}")->assertForbidden();
    // The new Owner did not request it.
    $this->actingAs($admin)->get($url)->assertNotFound();
    $this->actingAs($admin)->getJson("/api/v1/teams/{$this->team->id}/exports/{$id}")->assertNotFound();
});

it('hides the export once the team is in the trash', function () {
    $id = requestExport($this->owner, $this->team)->assertStatus(202)->json('data.id');
    $url = downloadUrlFor($id);

    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}")->assertNoContent();

    $this->actingAs($this->owner)->get($url)->assertNotFound();
    $this->actingAs($this->owner)->getJson("/api/v1/teams/{$this->team->id}/exports/{$id}")->assertNotFound();
});

it('reports an export past its 24 hours as expired, without a download', function () {
    $id = requestExport($this->owner, $this->team)->assertStatus(202)->json('data.id');
    $url = downloadUrlFor($id);

    $this->travel(24)->hours();
    $this->travel(1)->second();

    $this->actingAs($this->owner)->getJson("/api/v1/teams/{$this->team->id}/exports/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'expired')
        ->assertJsonPath('data.download_url', null)
        ->assertJsonPath('data.size_bytes', null);

    // The signed URL expires with the export.
    $this->actingAs($this->owner)->getJson($url)->assertForbidden();
});

it('answers 410 when a ready archive is gone, and 404 while an export is not ready', function () {
    $id = requestExport($this->owner, $this->team)->assertStatus(202)->json('data.id');
    $export = TeamExport::query()->findOrFail($id);
    $url = TeamExportResource::downloadUrl($export);

    Storage::disk('exports')->delete($export->storage_key);
    $this->actingAs($this->owner)->getJson($url)->assertStatus(410)->assertJsonPath('error.code', 'expired');

    $export->forceFill(['status' => TeamExportStatus::Processing, 'storage_key' => null, 'size_bytes' => null, 'sha256' => null, 'completed_at' => null])->save();
    $this->actingAs($this->owner)->getJson($url)->assertNotFound();
});

// --- The job re-checks everything when it runs -------------------------------------------------

it('refuses in the job when the team went to the trash, the requester lost ownership or left, or the export expired', function (Closure $change, string $reason) {
    Queue::fake();
    Log::spy();
    $id = requestExport($this->owner, $this->team)->assertStatus(202)->json('data.id');

    $change($this);
    app()->call([new BuildTeamExport($id), 'handle']);

    $export = TeamExport::query()->findOrFail($id);
    expect($export->status)->toBe(TeamExportStatus::Failed)
        ->and($export->storage_key)->toBeNull()
        ->and(Storage::disk('exports')->allFiles())->toBe([]);

    Log::shouldHaveReceived('warning')->with('team_export.refused', [
        'team_id' => $this->team->id, 'export_id' => $id, 'actor_id' => $this->owner->id, 'reason' => $reason,
    ])->once();
})->with([
    'team trashed' => [fn ($test) => $test->team->delete(), 'team_unavailable'],
    'team gone' => [fn ($test) => DB::table('teams')->where('id', $test->team->id)->update(['deleted_at' => now()->subDays(40)]), 'team_unavailable'],
    'ownership transferred' => [function ($test) {
        $admin = $test->members['admin'];
        $test->actingAs($test->owner)->postJson("/api/v1/teams/{$test->team->id}/ownership-transfer", ['new_owner_user_id' => $admin->id])->assertOk();
    }, 'not_owner'],
    'requester disabled' => [fn ($test) => $test->owner->forceFill(['disabled_at' => now()])->save(), 'requester_unavailable'],
    'expired before it ran' => [fn ($test) => test()->travel(25)->hours(), 'expired'],
]);

it('does nothing for an export that is gone or already finished', function () {
    Queue::fake();
    $id = requestExport($this->owner, $this->team)->assertStatus(202)->json('data.id');
    TeamExport::query()->whereKey($id)->update(['status' => 'failed']);

    app()->call([new BuildTeamExport($id), 'handle']);
    app()->call([new BuildTeamExport((string) Str::uuid7()), 'handle']);

    expect(TeamExport::query()->findOrFail($id)->status)->toBe(TeamExportStatus::Failed)
        ->and(Storage::disk('exports')->allFiles())->toBe([]);
});

it('re-checks the team before keeping the result: a trash during the build discards it', function () {
    Queue::fake();
    $id = requestExport($this->owner, $this->team)->assertStatus(202)->json('data.id');
    $team = $this->team;

    // The last section trashes the team while the archive is being written.
    app()->bind(StatusesSection::class, fn () => new class($team) implements TeamExportSection
    {
        public function __construct(private Team $team) {}

        public function name(): string
        {
            return 'statuses';
        }

        public function records(TeamExportContext $context): iterable
        {
            $this->team->delete();

            return [];
        }
    });

    app()->call([new BuildTeamExport($id), 'handle']);

    expect(TeamExport::query()->findOrFail($id)->status)->toBe(TeamExportStatus::Failed)
        ->and(Storage::disk('exports')->allFiles())->toBe([]);
});

it('marks the export failed and removes any file when the job finally fails', function () {
    Queue::fake();
    Log::spy();
    $id = requestExport($this->owner, $this->team)->assertStatus(202)->json('data.id');

    app()->bind(StatusesSection::class, fn () => new class implements TeamExportSection
    {
        public function name(): string
        {
            return 'statuses';
        }

        public function records(TeamExportContext $context): iterable
        {
            throw new RuntimeException('disk full');
        }
    });

    $job = new BuildTeamExport($id);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
    expect(TeamExport::query()->findOrFail($id)->status)->toBe(TeamExportStatus::Processing)
        ->and(Storage::disk('exports')->allFiles())->toBe([])
        ->and(is_dir("{$this->workRoot}/{$id}"))->toBeFalse();

    Storage::disk('exports')->put("archives/{$this->team->id}/{$id}.zip", 'partial');
    $job->failed(new RuntimeException('disk full'));

    expect(TeamExport::query()->findOrFail($id)->status)->toBe(TeamExportStatus::Failed)
        ->and(Storage::disk('exports')->allFiles())->toBe([]);
    Log::shouldHaveReceived('warning')->with('team_export.failed', ['team_id' => $this->team->id, 'export_id' => $id, 'actor_id' => $this->owner->id])->once();

    $this->actingAs($this->owner)->getJson("/api/v1/teams/{$this->team->id}/exports/{$id}")->assertOk()->assertJsonPath('data.status', 'failed');
});

it('builds in a deterministic work directory, clears what a killed attempt left there, and removes it afterwards', function () {
    Queue::fake();
    $id = requestExport($this->owner, $this->team)->assertStatus(202)->json('data.id');

    // A worker killed mid-build (SIGKILL on timeout or OOM) skips its cleanup.
    $workDir = "{$this->workRoot}/{$id}";
    mkdir("{$workDir}/data", 0700, true);
    file_put_contents("{$workDir}/data/leftover.json", '["plaintext from the killed attempt"]');
    $half = new ZipArchive;
    $half->open("{$workDir}/export.zip", ZipArchive::CREATE);
    $half->addFromString('data/leftover.json', '["half-written archive"]');
    $half->close();
    TeamExport::query()->whereKey($id)->update(['status' => 'processing']);

    app()->call([new BuildTeamExport($id), 'handle']);

    $export = TeamExport::query()->findOrFail($id);
    expect($export->status)->toBe(TeamExportStatus::Ready)
        ->and(is_dir($workDir))->toBeFalse()
        ->and(File::directories($this->workRoot))->toBe([])
        ->and(array_keys(exportEntries(Storage::disk('exports')->get($export->storage_key))))->not->toContain('data/leftover.json');
});

// --- Format and scoping -------------------------------------------------------------------------

it('keeps the section list well-formed for later tasks to extend', function () {
    $names = [];

    foreach (TeamExportFormat::SECTIONS as $class) {
        $section = app($class);
        expect($section)->toBeInstanceOf(TeamExportSection::class);
        $names[] = $section->name();
    }

    expect($names)->toBe(array_values(array_unique($names)))
        ->and(collect($names)->every(fn (string $name) => preg_match('/^[a-z][a-z0-9_]*$/', $name) === 1))->toBeTrue();
});

it('scopes sections to the projects the requester can see (DB-T02)', function () {
    $export = new TeamExport;
    $export->forceFill(['id' => (string) Str::uuid7()]);

    $forOwner = new TeamExportContext($this->team, $this->owner, $export, app(AccessibleProjects::class));
    $forAdmin = new TeamExportContext($this->team, $this->members['admin'], $export, app(AccessibleProjects::class));

    expect($forOwner->projectIds())->toEqualCanonicalizing([$this->public->id, $this->private->id])
        ->and($forAdmin->projectIds())->toBe([$this->public->id])
        ->and(collect(app(StatusesSection::class)->records($forAdmin))->pluck('project_id')->unique()->all())->toBe([$this->public->id]);
});
