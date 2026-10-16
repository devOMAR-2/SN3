<?php

use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Domain\Workflows\Actions\CreateStatus;
use App\Domain\Workflows\Actions\DeleteStatus;
use App\Domain\Workflows\Actions\ReorderStatuses;
use App\Domain\Workflows\Actions\UpdateStatus;
use App\Domain\Workflows\StatusCategory;
use App\Domain\Workflows\StatusRules;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ProjectStatus;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Tenancy\AccessibleProjects;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// CORE-004: project workflow statuses (API_SPEC.md §5, DATABASE.md §5, ADR-021).
// TEST-FR-CUST-001 (names, ordering, colours per project), TEST-FR-CUST-002 (default workflow),
// UC-005 / WF-01 (status deletion with a replacement), AUTH-04 (Viewer cannot change the
// workflow), DATABASE.md §16 DB-T01/DB-T02 (hidden project statuses are a 404), DB-T04 (no
// replacement status from another project).

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->members = [];

    foreach ([TeamRole::Admin, TeamRole::Member, TeamRole::Viewer] as $role) {
        $this->members[$role->value] = TeamMembership::factory()->for($this->team)->role($role)->create()->user;
    }

    $this->project = Project::factory()->for($this->team)->withDefaultStatuses()->create(['project_key' => 'WEB']);
    $this->outsider = Team::factory()->create()->owner;
});

/**
 * The project's statuses in board order as [name, category, position, is_default].
 *
 * @return list<array{0: string, 1: string, 2: int, 3: bool}>
 */
function wfBoard(Project $project): array
{
    return $project->statuses()->orderBy('position')->get()
        ->map(fn (ProjectStatus $s) => [$s->name, $s->category->value, $s->position, $s->is_default])
        ->all();
}

function wfStatus(Project $project, string $name): ProjectStatus
{
    return $project->statuses()->where('name', $name)->firstOrFail();
}

function wfJoinProject(Project $project, User $user, ProjectRole $role): void
{
    ProjectMembership::factory()->for($project)->member($user)->role($role)->create();
}

// --- Default workflow (FR-CUST-002, UC-001) ------------------------------------------------------------

it('seeds To do, In progress and Done in the creator\'s language when a project is created', function (string $locale, array $names) {
    $this->owner->forceFill(['locale' => $locale])->save();

    $id = $this->actingAs($this->owner)
        ->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => 'Website', 'key' => 'SITE'])
        ->assertCreated()
        ->json('data.id');

    expect(wfBoard(Project::query()->findOrFail($id)))->toBe([
        [$names[0], 'todo', 0, true],
        [$names[1], 'in_progress', 1, false],
        [$names[2], 'done', 2, false],
    ]);
})->with([
    'Arabic' => ['ar', ['للإنجاز', 'قيد التنفيذ', 'مكتمل']],
    'English' => ['en', ['To do', 'In progress', 'Done']],
]);

it('creates no statuses when project creation fails', function () {
    Project::factory()->for($this->team)->create(['project_key' => 'TAKEN']);
    $before = ProjectStatus::query()->count();

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => 'X', 'key' => 'TAKEN'])
        ->assertStatus(409);

    expect(ProjectStatus::query()->count())->toBe($before);
});

// --- Reading -----------------------------------------------------------------------------------------

it('lists statuses in board order to anyone who can see the project', function (string $actor) {
    $user = $actor === 'owner' ? $this->owner : $this->members[$actor];
    $todo = wfStatus($this->project, 'To do');

    $this->actingAs($user)->getJson("/api/v1/projects/{$this->project->id}/statuses")
        ->assertOk()
        ->assertJsonPath('data.*.name', ['To do', 'In progress', 'Done'])
        ->assertJsonPath('data.*.position', [0, 1, 2])
        ->assertJsonPath('data.*.category', ['todo', 'in_progress', 'done'])
        ->assertJsonPath('data.0', [
            'id' => $todo->id,
            'project_id' => $this->project->id,
            'name' => 'To do',
            'category' => 'todo',
            'color' => null,
            'position' => 0,
            'is_default' => true,
            'version' => 1,
            'created_at' => $todo->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $todo->updated_at?->utc()->toIso8601ZuluString(),
        ])
        ->assertJsonPath('meta.has_more', false);
})->with(['owner', 'admin', 'member', 'viewer']);

it('pages through statuses in board order', function () {
    $first = $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/statuses?limit=2")
        ->assertOk()
        ->assertJsonPath('data.*.position', [0, 1])
        ->assertJsonPath('meta.has_more', true);

    $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/statuses?limit=2&cursor=".urlencode($first->json('meta.next_cursor')))
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Done'])
        ->assertJsonPath('meta.has_more', false);
});

it('answers 404 for the statuses of a hidden, foreign or unknown project (DB-T01, DB-T02)', function () {
    $private = Project::factory()->for($this->team)->private()->withDefaultStatuses()->create(['name' => 'Secret merger']);
    $status = wfStatus($private, 'To do');

    $cases = [
        [$this->members['admin'], $private->id],
        [$this->members['member'], $private->id],
        [$this->outsider, $this->project->id],
        [$this->owner, (string) Str::uuid7()],
        [$this->owner, 'not-a-uuid'],
    ];

    foreach ($cases as [$user, $projectId]) {
        foreach ([
            ['GET', "/api/v1/projects/{$projectId}/statuses", []],
            ['POST', "/api/v1/projects/{$projectId}/statuses", ['name' => 'X', 'category' => 'todo']],
            ['PATCH', "/api/v1/projects/{$projectId}/statuses/{$status->id}", ['name' => 'X']],
            ['DELETE', "/api/v1/projects/{$projectId}/statuses/{$status->id}", []],
            ['PUT', "/api/v1/projects/{$projectId}/statuses/order", ['status_ids' => [$status->id]]],
        ] as [$method, $uri, $body]) {
            $response = $this->actingAs($user)->json($method, $uri, $body, ['If-Match' => '"1"'])
                ->assertNotFound()
                ->assertJsonPath('error.code', 'not_found');

            expect($response->getContent())->not->toContain('Secret merger');
        }
    }

    // The Owner sees the private workflow without a membership row.
    $this->actingAs($this->owner)->getJson("/api/v1/projects/{$private->id}/statuses")->assertOk()->assertJsonCount(3, 'data');
    expect($status->refresh())->name->toBe('To do')->version->toBe(1);
});

it('answers 404 for a status of another project, even one the caller manages', function () {
    $other = Project::factory()->for($this->team)->withDefaultStatuses()->create();
    $foreign = wfStatus($other, 'To do');

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/statuses/{$foreign->id}", ['name' => 'X'], ['If-Match' => '"1"'])
        ->assertNotFound();
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/statuses/{$foreign->id}", [], ['If-Match' => '"1"'])
        ->assertNotFound();
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/statuses/nope", ['name' => 'X'], ['If-Match' => '"1"'])
        ->assertNotFound();

    expect($foreign->refresh()->name)->toBe('To do');
});

it('requires authentication for every workflow endpoint', function (string $method, string $suffix) {
    $this->json($method, '/api/v1/projects/'.Str::uuid7().$suffix)->assertUnauthorized();
})->with([
    ['GET', '/statuses'],
    ['POST', '/statuses'],
    ['PUT', '/statuses/order'],
    ['PATCH', '/statuses/019a0000-0000-7000-8000-000000000000'],
    ['DELETE', '/statuses/019a0000-0000-7000-8000-000000000000'],
    ['GET', '/settings'],
    ['PATCH', '/settings'],
]);

// --- Who may change the workflow --------------------------------------------------------------------

/**
 * [team role, visibility, explicit project role, can view, can manage the workflow]. Managing
 * follows ProjectPolicy::update (API_SPEC.md §5, §17; ADR-020 §5).
 */
dataset('workflow access', [
    'Owner, private, no membership' => ['owner', 'private', null, true, true],
    'Admin, team, no membership' => ['admin', 'team', null, true, true],
    'Admin, private, no membership' => ['admin', 'private', null, false, false],
    'Admin, private, manager' => ['admin', 'private', 'manager', true, true],
    'Admin, private, contributor' => ['admin', 'private', 'contributor', true, false],
    'Member, team, no membership' => ['member', 'team', null, true, false],
    'Member, team, manager' => ['member', 'team', 'manager', true, true],
    'Member, private, contributor' => ['member', 'private', 'contributor', true, false],
    'Member, private, no membership' => ['member', 'private', null, false, false],
    'Viewer, team, no membership' => ['viewer', 'team', null, true, false],
    'Viewer, team, manager' => ['viewer', 'team', 'manager', true, false],
    'Viewer, private, manager' => ['viewer', 'private', 'manager', true, false],
    'other team' => ['outsider', 'team', null, false, false],
]);

it('lets exactly the project managers change the workflow and settings', function (string $role, string $visibility, ?string $projectRole, bool $view, bool $manage) {
    $project = Project::factory()->for($this->team)->withDefaultStatuses()->create(['visibility' => $visibility]);
    $actor = match ($role) {
        'owner' => $this->owner,
        'outsider' => $this->outsider,
        default => $this->members[$role],
    };

    if ($projectRole !== null) {
        wfJoinProject($project, $actor, ProjectRole::from($projectRole));
    }

    $denied = $view ? 403 : 404;
    $base = "/api/v1/projects/{$project->id}";
    $doing = wfStatus($project, 'In progress');

    $list = $this->actingAs($actor)->getJson("{$base}/statuses");
    $view ? $list->assertOk() : $list->assertNotFound();
    $settings = $this->actingAs($actor)->getJson("{$base}/settings");
    $view ? $settings->assertOk() : $settings->assertNotFound();

    $create = $this->actingAs($actor)->postJson("{$base}/statuses", ['name' => 'Review', 'category' => 'in_progress']);
    $manage ? $create->assertCreated() : $create->assertStatus($denied);

    $rename = $this->actingAs($actor)->patchJson("{$base}/statuses/{$doing->id}", ['name' => 'Doing'], ['If-Match' => '"1"']);
    $manage ? $rename->assertOk() : $rename->assertStatus($denied);

    $ids = $project->statuses()->orderByDesc('position')->pluck('id')->all();
    $reorder = $this->actingAs($actor)->putJson("{$base}/statuses/order", ['status_ids' => $ids]);
    $manage ? $reorder->assertOk() : $reorder->assertStatus($denied);

    $delete = $this->actingAs($actor)->deleteJson("{$base}/statuses/{$doing->id}", [], ['If-Match' => $manage ? '"2"' : '"1"']);
    $manage ? $delete->assertNoContent() : $delete->assertStatus($denied);

    $patch = $this->actingAs($actor)->patchJson("{$base}/settings", ['sprints_enabled' => true], ['If-Match' => '"1"']);
    $manage ? $patch->assertOk() : $patch->assertStatus($denied);

    expect(wfBoard($project))->toBe($manage
        ? [['Review', 'in_progress', 0, false], ['Done', 'done', 1, false], ['To do', 'todo', 2, true]]
        : [['To do', 'todo', 0, true], ['In progress', 'in_progress', 1, false], ['Done', 'done', 2, false]])
        ->and($project->refresh()->sprints_enabled)->toBe($manage);
})->with('workflow access');

it('answers 403 to a Viewer project manager whatever the body (AUTH-04)', function (string $method, string $suffix, array $body) {
    $viewer = $this->members['viewer'];
    wfJoinProject($this->project, $viewer, ProjectRole::Manager);
    $suffix = str_replace('{status}', wfStatus($this->project, 'Done')->id, $suffix);

    $this->actingAs($viewer)->json($method, "/api/v1/projects/{$this->project->id}{$suffix}", $body, ['If-Match' => '"1"'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect(wfBoard($this->project))->toHaveCount(3)
        ->and(wfStatus($this->project, 'Done')->version)->toBe(1)
        ->and($this->project->refresh())->sprints_enabled->toBeFalse()->version->toBe(1);
})->with([
    'create, valid' => ['POST', '/statuses', ['name' => 'Review', 'category' => 'todo']],
    'create, invalid' => ['POST', '/statuses', ['category' => 'nope', 'junk' => 1]],
    'update, valid' => ['PATCH', '/statuses/{status}', ['name' => 'Shipped']],
    'update, invalid' => ['PATCH', '/statuses/{status}', ['position' => 0]],
    'delete' => ['DELETE', '/statuses/{status}', []],
    'reorder, invalid' => ['PUT', '/statuses/order', ['status_ids' => 'x']],
    'settings, valid' => ['PATCH', '/settings', ['sprints_enabled' => true]],
    'settings, invalid' => ['PATCH', '/settings', ['story_points' => 'on']],
]);

// --- Create ---------------------------------------------------------------------------------------------

it('adds a status at the end of the workflow', function () {
    Log::spy();

    $response = $this->actingAs($this->owner)
        ->postJson("/api/v1/projects/{$this->project->id}/statuses", ['name' => '  مراجعة  ', 'category' => 'in_progress', 'color' => ' #1a2b3c '])
        ->assertCreated()
        ->assertJsonPath('data.name', 'مراجعة')
        ->assertJsonPath('data.category', 'in_progress')
        ->assertJsonPath('data.color', '#1A2B3C')
        ->assertJsonPath('data.position', 3)
        ->assertJsonPath('data.is_default', false)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.project_id', $this->project->id);

    expect(wfBoard($this->project)[3])->toBe(['مراجعة', 'in_progress', 3, false])
        ->and($this->project->refresh()->version)->toBe(1);

    Log::shouldHaveReceived('info')->with('workflow.status_created', [
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'status_id' => $response->json('data.id'),
        'actor_id' => $this->owner->id,
        'category' => 'in_progress',
    ])->once();
});

it('validates new statuses with stable codes', function (array $body, string $field, string $code) {
    $this->actingAs($this->owner)
        ->postJson("/api/v1/projects/{$this->project->id}/statuses", [...['name' => 'Review', 'category' => 'todo'], ...$body])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath("error.fields.{$field}.0", $code);

    expect(wfBoard($this->project))->toHaveCount(3);
})->with([
    'missing name' => [['name' => null], 'name', 'required'],
    'blank name' => [['name' => '   '], 'name', 'required'],
    'long name' => [['name' => str_repeat('a', 51)], 'name', 'max'],
    'missing category' => [['category' => null], 'category', 'required'],
    'unknown category' => [['category' => 'blocked'], 'category', 'in'],
    'short colour' => [['color' => '#FFF'], 'color', 'regex'],
    'named colour' => [['color' => 'red'], 'color', 'regex'],
    'not hex' => [['color' => '#GGGGGG'], 'color', 'regex'],
    'read-only position' => [['position' => 0], 'position', 'unknown_field'],
    'read-only default flag' => [['is_default' => true], 'is_default', 'unknown_field'],
    'unknown field' => [['team_id' => 'x'], 'team_id', 'unknown_field'],
]);

it('accepts a 50-character Arabic name and no colour', function () {
    $this->actingAs($this->owner)
        ->postJson("/api/v1/projects/{$this->project->id}/statuses", ['name' => str_repeat('م', 50), 'category' => 'done', 'color' => null])
        ->assertCreated()
        ->assertJsonPath('data.color', null);
});

it('keeps status names unique per project ignoring case, but not across projects', function () {
    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/statuses", ['name' => 'DONE', 'category' => 'done'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/statuses/".wfStatus($this->project, 'Done')->id, ['name' => 'to DO'], ['If-Match' => '"1"'])
        ->assertStatus(409);

    $other = Project::factory()->for($this->team)->withDefaultStatuses()->create();
    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$other->id}/statuses", ['name' => 'Review', 'category' => 'todo'])->assertCreated();
    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/statuses", ['name' => 'Review', 'category' => 'todo'])->assertCreated();

    expect(wfStatus($this->project, 'Done')->version)->toBe(1);
});

it('caps a workflow at the status limit (409)', function () {
    for ($i = 3; $i < StatusRules::MAX_STATUSES; $i++) {
        ProjectStatus::factory()->for($this->project)->create(['name' => "S{$i}"]);
    }

    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/statuses", ['name' => 'One more', 'category' => 'todo'])
        ->assertStatus(409);

    expect($this->project->statuses()->count())->toBe(StatusRules::MAX_STATUSES);
});

// --- Update ------------------------------------------------------------------------------------------

it('renames, recolours and recategorises a status against its version', function () {
    $doing = wfStatus($this->project, 'In progress');

    $this->actingAs($this->members['admin'])
        ->patchJson("/api/v1/projects/{$this->project->id}/statuses/{$doing->id}", ['name' => ' Doing ', 'color' => '#abcdef', 'category' => 'todo'], ['If-Match' => '"1"'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Doing')
        ->assertJsonPath('data.color', '#ABCDEF')
        ->assertJsonPath('data.category', 'todo')
        ->assertJsonPath('data.position', 1)
        ->assertJsonPath('data.version', 2);

    // Clearing the colour; a request that changes nothing keeps the version.
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/statuses/{$doing->id}", ['color' => null], ['If-Match' => '"2"'])
        ->assertOk()
        ->assertJsonPath('data.color', null)
        ->assertJsonPath('data.version', 3);
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/statuses/{$doing->id}", ['name' => 'Doing'], ['If-Match' => '"3"'])
        ->assertOk()
        ->assertJsonPath('data.version', 3);

    expect(wfBoard($this->project))->toBe([['To do', 'todo', 0, true], ['Doing', 'todo', 1, false], ['Done', 'done', 2, false]])
        ->and($this->project->refresh()->version)->toBe(1);
});

it('requires If-Match on status changes (428) and refuses a stale version (412)', function () {
    $done = wfStatus($this->project, 'Done');
    $done->forceFill(['version' => 3])->save();
    $uri = "/api/v1/projects/{$this->project->id}/statuses/{$done->id}";

    $this->actingAs($this->owner)->patchJson($uri, ['name' => 'X'])->assertStatus(428)->assertJsonPath('error.code', 'precondition_required');
    $this->actingAs($this->owner)->patchJson($uri, ['name' => 'X'], ['If-Match' => '"2"'])->assertStatus(412)->assertJsonPath('error.code', 'version_mismatch');
    $this->actingAs($this->owner)->deleteJson($uri)->assertStatus(428);
    $this->actingAs($this->owner)->deleteJson($uri, [], ['If-Match' => '"2"'])->assertStatus(412);
    $this->actingAs($this->owner)->deleteJson($uri, [], ['If-Match' => '*'])->assertStatus(412);

    expect($done->refresh())->name->toBe('Done')->version->toBe(3)
        ->and(wfBoard($this->project))->toHaveCount(3);
});

it('validates status changes with stable codes', function (array $body, string $field, string $code) {
    $done = wfStatus($this->project, 'Done');

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/statuses/{$done->id}", $body, ['If-Match' => '"1"'])
        ->assertUnprocessable()
        ->assertJsonPath("error.fields.{$field}.0", $code);

    expect($done->refresh()->version)->toBe(1);
})->with([
    'null name' => [['name' => null], 'name', 'required'],
    'long name' => [['name' => str_repeat('a', 51)], 'name', 'max'],
    'null category' => [['category' => null], 'category', 'required'],
    'unknown category' => [['category' => 'archived'], 'category', 'in'],
    'bad colour' => [['color' => '#12345'], 'color', 'regex'],
    'position is reordered elsewhere' => [['position' => 0], 'position', 'unknown_field'],
    'default flag is read-only' => [['is_default' => true], 'is_default', 'unknown_field'],
]);

it('keeps at least one todo and one done status when recategorising (409)', function (string $name, string $category) {
    $status = wfStatus($this->project, $name);

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/statuses/{$status->id}", ['category' => $category], ['If-Match' => '"1"'])
        ->assertStatus(409);

    expect($status->refresh())->category->not->toBe(StatusCategory::from($category))->version->toBe(1);
})->with([
    'last todo' => ['To do', 'in_progress'],
    'last done' => ['Done', 'todo'],
]);

it('lets the last todo status change category once another todo status exists', function () {
    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/statuses", ['name' => 'Backlog', 'category' => 'todo'])->assertCreated();

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/statuses/".wfStatus($this->project, 'To do')->id, ['category' => 'in_progress'], ['If-Match' => '"1"'])
        ->assertOk();
});

// --- Delete (UC-005, WF-01) -------------------------------------------------------------------------------

it('deletes a status and closes the gap in the order', function () {
    Log::spy();
    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/statuses", ['name' => 'Review', 'category' => 'in_progress'])->assertCreated();
    $doing = wfStatus($this->project, 'In progress');

    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/statuses/{$doing->id}", [], ['If-Match' => '"1"'])
        ->assertNoContent();

    expect(wfBoard($this->project))->toBe([['To do', 'todo', 0, true], ['Done', 'done', 1, false], ['Review', 'in_progress', 2, false]])
        ->and(ProjectStatus::query()->whereKey($doing->id)->exists())->toBeFalse();

    Log::shouldHaveReceived('info')->with('workflow.status_deleted', [
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'status_id' => $doing->id,
        'actor_id' => $this->owner->id,
        'replacement_status_id' => null,
    ])->once();
});

it('accepts a same-project replacement and refuses any other (WF-01, DB-T04)', function () {
    $doing = wfStatus($this->project, 'In progress');
    $uri = "/api/v1/projects/{$this->project->id}/statuses/{$doing->id}";
    $foreign = Project::factory()->for($this->team)->withDefaultStatuses()->create()->statuses()->firstOrFail();

    foreach ([
        [$foreign->id, 'exists'],
        [(string) Str::uuid7(), 'exists'],
        [$doing->id, 'different'],
        ['not-a-uuid', 'uuid'],
    ] as [$replacement, $code]) {
        $this->actingAs($this->owner)->deleteJson($uri, ['replacement_status_id' => $replacement], ['If-Match' => '"1"'])
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.replacement_status_id.0', $code);
    }

    $this->actingAs($this->owner)->deleteJson($uri, ['junk' => 1], ['If-Match' => '"1"'])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.junk.0', 'unknown_field');

    expect(wfBoard($this->project))->toHaveCount(3);

    $done = wfStatus($this->project, 'Done');
    $this->actingAs($this->owner)->deleteJson($uri, ['replacement_status_id' => strtoupper($done->id)], ['If-Match' => '"1"'])
        ->assertNoContent();

    expect(wfBoard($this->project))->toBe([['To do', 'todo', 0, true], ['Done', 'done', 1, false]]);
});

it('never deletes the last todo or done status (409)', function (string $name) {
    $status = wfStatus($this->project, $name);

    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/statuses/{$status->id}", [], ['If-Match' => '"1"'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');

    expect(wfBoard($this->project))->toHaveCount(3);
})->with(['To do', 'Done']);

it('moves the default to the replacement, or else to the first todo status, when the default is deleted', function () {
    $later = ProjectStatus::factory()->for($this->project)->category(StatusCategory::Todo)->create(['name' => 'Later']);
    $icebox = ProjectStatus::factory()->for($this->project)->category(StatusCategory::Todo)->create(['name' => 'Icebox']);
    $todo = wfStatus($this->project, 'To do');

    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/statuses/{$todo->id}", ['replacement_status_id' => $icebox->id], ['If-Match' => '"1"'])
        ->assertNoContent();

    expect($this->project->statuses()->where('is_default', true)->pluck('name')->all())->toBe(['Icebox']);

    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/statuses/{$icebox->id}", [], ['If-Match' => '"1"'])
        ->assertNoContent();

    expect(wfBoard($this->project))->toBe([['In progress', 'in_progress', 0, false], ['Done', 'done', 1, false], ['Later', 'todo', 2, true]])
        ->and($later->refresh()->is_default)->toBeTrue();
});

// --- Reorder -------------------------------------------------------------------------------------------

it('reorders the whole workflow and returns it in the new order', function () {
    Log::spy();
    [$todo, $doing, $done] = [wfStatus($this->project, 'To do'), wfStatus($this->project, 'In progress'), wfStatus($this->project, 'Done')];

    $this->actingAs($this->owner)
        ->putJson("/api/v1/projects/{$this->project->id}/statuses/order", ['status_ids' => [$done->id, strtoupper($todo->id), $doing->id]])
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Done', 'To do', 'In progress'])
        ->assertJsonPath('data.*.position', [0, 1, 2])
        ->assertJsonPath('data.*.version', [1, 1, 1]);

    expect(wfBoard($this->project))->toBe([['Done', 'done', 0, false], ['To do', 'todo', 1, true], ['In progress', 'in_progress', 2, false]]);

    // The same order again is a no-op.
    $this->actingAs($this->owner)
        ->putJson("/api/v1/projects/{$this->project->id}/statuses/order", ['status_ids' => [$done->id, $todo->id, $doing->id]])
        ->assertOk();

    Log::shouldHaveReceived('info')->with('workflow.statuses_reordered', [
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'actor_id' => $this->owner->id,
    ])->twice();
});

it('refuses an order that does not list exactly the project\'s statuses (409)', function () {
    $ids = $this->project->statuses()->orderBy('position')->pluck('id')->all();
    $foreign = Project::factory()->for($this->team)->withDefaultStatuses()->create()->statuses()->firstOrFail()->id;

    foreach ([
        'missing one' => array_slice($ids, 0, 2),
        'foreign status' => [...array_slice($ids, 0, 2), $foreign],
        'extra status' => [...$ids, $foreign],
        'random id' => [...array_slice($ids, 0, 2), (string) Str::uuid7()],
    ] as $list) {
        $this->actingAs($this->owner)->putJson("/api/v1/projects/{$this->project->id}/statuses/order", ['status_ids' => $list])
            ->assertStatus(409);
    }

    expect(wfBoard($this->project))->toBe([['To do', 'todo', 0, true], ['In progress', 'in_progress', 1, false], ['Done', 'done', 2, false]]);
});

it('validates the order body with stable codes', function (mixed $ids, string $field, string $code) {
    $first = $this->project->statuses()->orderBy('position')->value('id');
    $ids = is_array($ids) ? array_map(fn ($id) => $id === '{first}' ? $first : $id, $ids) : $ids;

    $fields = $this->actingAs($this->owner)->putJson("/api/v1/projects/{$this->project->id}/statuses/order", ['status_ids' => $ids])
        ->assertUnprocessable()
        ->json('error.fields');

    // Field paths such as `status_ids.1` are keys, not nested objects.
    expect($fields[$field][0] ?? null)->toBe($code);
})->with([
    'missing' => [null, 'status_ids', 'required'],
    'empty' => [[], 'status_ids', 'required'],
    'not a list' => [['a' => '{first}'], 'status_ids', 'list'],
    'a string' => ['x', 'status_ids', 'list'],
    'not a uuid' => [['{first}', 'nope'], 'status_ids.1', 'uuid'],
    'duplicate' => [['{first}', '{first}'], 'status_ids.0', 'distinct'],
    'too many' => [array_fill(0, StatusRules::MAX_STATUSES + 1, '{first}'), 'status_ids', 'max'],
]);

// --- Archived team or project: read-only (423) ------------------------------------------------------------

it('refuses every workflow write to an archived project or team (423) but still serves reads', function (string $archived) {
    $archived === 'team'
        ? $this->team->forceFill(['archived_at' => now()])->save()
        : $this->project->forceFill(['archived_at' => now()])->save();
    $base = "/api/v1/projects/{$this->project->id}";
    $done = wfStatus($this->project, 'Done');
    $ids = $this->project->statuses()->pluck('id')->all();

    foreach ([
        ['POST', "{$base}/statuses", ['name' => 'X', 'category' => 'todo']],
        // A stale version still answers 423 (423 wins over 412).
        ['PATCH', "{$base}/statuses/{$done->id}", ['name' => 'X']],
        ['DELETE', "{$base}/statuses/{$done->id}", []],
        ['PUT', "{$base}/statuses/order", ['status_ids' => $ids]],
        ['PATCH', "{$base}/settings", ['sprints_enabled' => true]],
    ] as [$method, $uri, $body]) {
        $this->actingAs($this->owner)->json($method, $uri, $body, ['If-Match' => '"9"'])
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'resource_locked');
    }

    $this->actingAs($this->owner)->getJson("{$base}/statuses")->assertOk()->assertJsonCount(3, 'data');
    $this->actingAs($this->owner)->getJson("{$base}/settings")->assertOk();

    expect(wfBoard($this->project))->toHaveCount(3)
        ->and($done->refresh())->name->toBe('Done')->version->toBe(1);
})->with(['team', 'project']);

// --- Re-checks under the lock ---------------------------------------------------------------------------

it('re-checks the actor on the locked rows, so a demoted manager cannot change the workflow', function () {
    $project = Project::factory()->for($this->team)->private()->withDefaultStatuses()->create();
    $manager = $this->members['member'];
    wfJoinProject($project, $manager, ProjectRole::Manager);
    $stale = app(AccessibleProjects::class)->findOrFail($manager, $project->id);
    $doing = wfStatus($project, 'In progress');
    $ids = $project->statuses()->pluck('id')->all();

    // The demotion commits after the manager's request passed its policy check.
    $this->actingAs($this->owner)->putJson("/api/v1/projects/{$project->id}/members/{$manager->id}", ['role' => 'contributor'])->assertOk();

    expect(fn () => app(CreateStatus::class)->handle($stale, $manager, 'Late', StatusCategory::Todo))->toThrow(DomainConflictException::class)
        ->and(fn () => app(UpdateStatus::class)->handle($stale, $doing, $manager, 1, ['name' => 'Late']))->toThrow(DomainConflictException::class)
        ->and(fn () => app(DeleteStatus::class)->handle($stale, $doing, $manager, 1))->toThrow(DomainConflictException::class)
        ->and(fn () => app(ReorderStatuses::class)->handle($stale, $manager, array_reverse($ids)))->toThrow(DomainConflictException::class)
        ->and(wfBoard($project))->toBe([['To do', 'todo', 0, true], ['In progress', 'in_progress', 1, false], ['Done', 'done', 2, false]]);
});

it('answers 404 when the project became invisible while the request waited', function () {
    $admin = $this->members['admin'];
    $stale = app(AccessibleProjects::class)->findOrFail($admin, $this->project->id);

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}", ['visibility' => 'private'], ['If-Match' => '"1"'])->assertOk();

    expect(fn () => app(CreateStatus::class)->handle($stale, $admin, 'Late', StatusCategory::Todo))->toThrow(ModelNotFoundException::class)
        ->and(wfBoard($this->project))->toHaveCount(3);
});

it('answers 404 for a status deleted while the request waited', function () {
    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/statuses", ['name' => 'Review', 'category' => 'todo'])->assertCreated();
    $review = wfStatus($this->project, 'Review');
    $stale = clone $review;

    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/statuses/{$review->id}", [], ['If-Match' => '"1"'])->assertNoContent();

    expect(fn () => app(UpdateStatus::class)->handle($this->project, $stale, $this->owner, 1, ['name' => 'Again']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(DeleteStatus::class)->handle($this->project, $stale, $this->owner, 1))->toThrow(ModelNotFoundException::class);
});

it('logs workflow events with identifiers only', function () {
    Log::spy();
    $project = Project::factory()->for($this->team)->private()->withDefaultStatuses()->create(['name' => 'Secret merger']);
    $doing = wfStatus($project, 'In progress');

    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$project->id}/statuses", ['name' => 'Due diligence', 'category' => 'todo'])->assertCreated();
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$project->id}/statuses/{$doing->id}", ['name' => 'Negotiation', 'category' => 'done'], ['If-Match' => '"1"'])->assertOk();

    Log::shouldHaveReceived('info')->with('workflow.status_recategorised', [
        'team_id' => $this->team->id,
        'project_id' => $project->id,
        'status_id' => $doing->id,
        'actor_id' => $this->owner->id,
        'from' => 'in_progress',
        'to' => 'done',
    ])->once();
    Log::shouldNotHaveReceived('info', fn (string $message, array $context = []) => preg_match('/Secret merger|Due diligence|Negotiation/', (string) json_encode($context)) === 1);
});
