<?php

require_once __DIR__.'/TaskHelpers.php';

use App\Domain\Teams\TeamRole;
use App\Models\Label;
use App\Models\Task;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// CORE-005: GET /projects/{project}/tasks filters, sort orders and cursor pagination (API_SPEC.md
// §1.1, §1.4, §6.1). TEST-FR-TASK-001, TEST-FR-TASK-008 ("scoped task retrieval");
// DATABASE.md §16 DB-T13 (Arabic and English titles, search and sorting).

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->member = taskTeamMember($this->team, TeamRole::Member);
    $this->project = taskProject($this->team, attributes: ['project_key' => 'WEB']);
    $this->url = "/api/v1/projects/{$this->project->id}/tasks";
});

/**
 * @return list<string>
 */
function taskListedIds($response): array
{
    return collect($response->json('data'))->pluck('id')->all();
}

it('lists only live tasks of this project, newest first, with label IDs', function () {
    $label = Label::factory()->for($this->project)->create();
    $old = taskIn($this->project, ['created_at' => now()->subDay()]);
    $new = taskIn($this->project);
    DB::table('task_labels')->insert(['task_id' => $new->id, 'project_id' => $this->project->id, 'label_id' => $label->id]);
    taskIn(taskProject($this->team));
    taskIn($this->project)->delete();

    $response = $this->actingAs($this->member)->getJson($this->url)->assertOk()
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonPath('meta.next_cursor', null)
        ->assertJsonPath('meta.limit', 25)
        ->assertJsonPath('data.0.label_ids', [$label->id]);

    expect(taskListedIds($response))->toBe([$new->id, $old->id]);
});

it('pages with a cursor, without duplicates or gaps, and needs no more queries per page as tasks grow', function () {
    $tasks = collect(range(1, 7))->map(fn (int $i) => taskIn($this->project, ['created_at' => now()->subMinutes($i)]));

    $seen = [];
    $cursor = null;

    do {
        $response = $this->actingAs($this->member)->getJson($this->url.'?limit=3'.($cursor ? '&cursor='.urlencode($cursor) : ''))->assertOk();
        $seen = [...$seen, ...taskListedIds($response)];
        $cursor = $response->json('meta.next_cursor');
    } while ($cursor !== null);

    expect($seen)->toBe($tasks->pluck('id')->all());

    $this->actingAs($this->member)->getJson($this->url.'?cursor=forged')->assertStatus(400);
    $this->actingAs($this->member)->getJson($this->url.'?limit=101')->assertUnprocessable();

    DB::enableQueryLog();
    $this->actingAs($this->member)->getJson($this->url.'?limit=100')->assertOk();
    $queries = count(DB::getQueryLog());
    collect(range(1, 5))->each(fn () => taskIn($this->project));
    DB::flushQueryLog();
    $this->actingAs($this->member)->getJson($this->url.'?limit=100')->assertOk();

    expect(count(DB::getQueryLog()))->toBe($queries);
});

it('filters by status, assignee, priority, label and due-date range', function () {
    $done = taskStatusId($this->project, 'done');
    $bug = Label::factory()->for($this->project)->create();
    $ui = Label::factory()->for($this->project)->create();

    $a = taskIn($this->project, ['status_id' => $done, 'priority' => 'high', 'due_date' => '2026-10-10']);
    $b = taskIn($this->project, ['assignee_user_id' => $this->member->id, 'priority' => 'urgent', 'due_date' => '2026-10-20']);
    $c = taskIn($this->project, ['due_date' => '2026-10-30']);
    $d = taskIn($this->project);
    DB::table('task_labels')->insert([
        ['task_id' => $a->id, 'project_id' => $this->project->id, 'label_id' => $bug->id],
        ['task_id' => $b->id, 'project_id' => $this->project->id, 'label_id' => $ui->id],
        ['task_id' => $c->id, 'project_id' => $this->project->id, 'label_id' => $bug->id],
        ['task_id' => $c->id, 'project_id' => $this->project->id, 'label_id' => $ui->id],
    ]);

    $ids = fn (string $query) => taskListedIds($this->actingAs($this->member)->getJson($this->url.'?'.$query)->assertOk());

    expect($ids('status_id[]='.$done))->toBe([$a->id])
        ->and($ids('status_id[]='.$done.'&status_id[]='.taskDefaultStatusId($this->project)))->toEqualCanonicalizing([$a->id, $b->id, $c->id, $d->id])
        ->and($ids('assignee_user_id='.strtoupper($this->member->id)))->toBe([$b->id])
        ->and($ids('priority[]=high&priority[]=urgent'))->toEqualCanonicalizing([$a->id, $b->id])
        ->and($ids('label_id[]='.$bug->id))->toEqualCanonicalizing([$a->id, $c->id])
        ->and($ids('label_id[]='.$bug->id.'&label_id[]='.$ui->id))->toEqualCanonicalizing([$a->id, $b->id, $c->id])
        ->and($ids('due_after=2026-10-20'))->toEqualCanonicalizing([$b->id, $c->id])
        ->and($ids('due_before=2026-10-20'))->toEqualCanonicalizing([$a->id, $b->id])
        ->and($ids('due_after=2026-10-20&due_before=2026-10-20'))->toBe([$b->id])
        ->and($ids('status_id[]='.Str::uuid()))->toBe([])
        ->and($ids('priority[]=high&label_id[]='.$bug->id.'&due_before=2026-10-15'))->toBe([$a->id]);
});

it('searches titles in Arabic and English and finds tasks by number or key (DB-T13)', function () {
    $arabic = taskIn($this->project, ['title' => 'إصلاح صفحة تسجيل الدخول']);
    $english = taskIn($this->project, ['title' => 'Fix LOGIN page']);
    $literal = taskIn($this->project, ['title' => '100% done_now']);
    taskIn($this->project, ['title' => 'Unrelated']);

    $ids = fn (string $q) => taskListedIds($this->actingAs($this->member)->getJson($this->url.'?q='.urlencode($q))->assertOk());

    expect($ids('تسجيل'))->toBe([$arabic->id])
        ->and($ids('login'))->toBe([$english->id])
        ->and($ids('%'))->toBe([$literal->id])
        ->and($ids('_'))->toBe([$literal->id])
        ->and($ids('2'))->toBe([$english->id])
        ->and($ids('web-1'))->toBe([$arabic->id])
        ->and($ids('OTHER-1'))->toBe([]);

    $this->actingAs($this->member)->getJson($this->url.'?q='.str_repeat('a', 201))->assertUnprocessable()
        ->assertJsonPath('error.fields.q', ['max']);
});

it('sorts by every allowlisted field with stable keyset pages', function () {
    $low = taskIn($this->project, ['priority' => 'low', 'due_date' => '2026-12-01', 'created_at' => now()->subMinutes(3)]);
    $urgent = taskIn($this->project, ['priority' => 'urgent', 'created_at' => now()->subMinutes(2)]);
    $none = taskIn($this->project, ['due_date' => '2026-11-01', 'created_at' => now()->subMinute()]);
    $low->forceFill(['updated_at' => now()->addMinute()])->saveQuietly();

    $ids = function (string $sort, int $limit = 100): array {
        $all = [];
        $cursor = null;

        do {
            $response = $this->actingAs($this->member)->getJson($this->url."?sort={$sort}&limit={$limit}".($cursor ? '&cursor='.urlencode($cursor) : ''))->assertOk();
            $all = [...$all, ...taskListedIds($response)];
            $cursor = $response->json('meta.next_cursor');
        } while ($cursor !== null);

        return $all;
    };

    foreach ([100, 1] as $limit) {
        expect($ids('created_at', $limit))->toBe([$none->id, $urgent->id, $low->id])
            ->and($ids('updated_at', $limit)[0])->toBe($low->id)
            ->and($ids('priority', $limit))->toBe([$urgent->id, $low->id, $none->id])
            ->and($ids('due_date', $limit))->toBe([$none->id, $low->id, $urgent->id])
            ->and($ids('position', $limit))->toBe([$low->id, $urgent->id, $none->id]);
    }
});

it('validates the list query with stable codes', function (string $query, string $field, string $code) {
    $fields = $this->actingAs($this->member)->getJson($this->url.'?'.$query)->assertUnprocessable()->json('error.fields');

    expect($fields[$field][0] ?? null)->toBe($code);
})->with([
    'sort not allowlisted' => ['sort=title', 'sort', 'in'],
    'sort injection' => ['sort=created_at;drop table tasks', 'sort', 'in'],
    'status not a list' => ['status_id=abc', 'status_id', 'array'],
    'malformed status' => ['status_id[]=abc', 'status_id.0', 'uuid'],
    'malformed assignee' => ['assignee_user_id=me', 'assignee_user_id', 'uuid'],
    'unknown priority' => ['priority[]=p1', 'priority.0', 'in'],
    'malformed label' => ['label_id[]=1', 'label_id.0', 'uuid'],
    'malformed due date' => ['due_after=tomorrow', 'due_after', 'date_format'],
    'inverted range' => ['due_after=2026-10-20&due_before=2026-10-01', 'due_before', 'after_or_equal'],
    'sprint before FLEX-005' => ['sprint_id='.'018f8c70-0000-7000-8000-000000000001', 'sprint_id', 'prohibited'],
]);

it('cannot be used to read tasks of another project through filters', function () {
    $other = taskProject($this->team, 'private');
    $hidden = taskIn($other, ['assignee_user_id' => $this->owner->id]);

    $response = $this->actingAs($this->member)->getJson($this->url.'?status_id[]='.$hidden->status_id.'&assignee_user_id='.$this->owner->id)->assertOk();

    expect(taskListedIds($response))->toBe([]);
});
