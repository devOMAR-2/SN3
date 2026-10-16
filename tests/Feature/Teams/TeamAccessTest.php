<?php

use App\Domain\Teams\TeamRole;
use App\Http\Pagination\CursorPagination;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;

// TEST-FR-AUTH-003: one user belongs to several teams and lists them to switch the active team
// context. FND-005 acceptance: cross-team IDs always deny, indistinguishably from missing ones.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->owned = Team::factory()->ownedBy($this->user)->create(['name' => 'فريق سنع', 'created_at' => now()->subDays(2)]);
    $this->joined = Team::factory()->create(['name' => 'Website', 'created_at' => now()->subDay()]);
    TeamMembership::factory()->for($this->joined)->for($this->user)->role(TeamRole::Viewer)->create();

    // Another tenant: the user has no membership here.
    $this->foreign = Team::factory()->create(['name' => 'Secret Corp', 'slug' => 'secret-corp']);
});

it('lists every team of the user, newest first, with the user role in each', function (string $path) {
    $this->actingAs($this->user)
        ->getJson($path)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $this->joined->id)
        ->assertJsonPath('data.0.role', 'viewer')
        ->assertJsonPath('data.1.id', $this->owned->id)
        ->assertJsonPath('data.1.name', 'فريق سنع')
        ->assertJsonPath('data.1.role', 'owner')
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonPath('meta.next_cursor', null)
        ->assertJsonPath('meta.limit', 25)
        ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'owner_user_id', 'role', 'archived_at', 'created_at', 'updated_at']], 'meta' => ['request_id']])
        ->assertDontSee('Secret Corp');
})->with(['/api/v1/me/teams', '/api/v1/teams']);

it('shows a team the user belongs to', function () {
    $this->actingAs($this->user)
        ->getJson("/api/v1/teams/{$this->joined->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->joined->id)
        ->assertJsonPath('data.role', 'viewer');
});

it('answers a team of another tenant exactly like a missing one', function () {
    $foreign = $this->actingAs($this->user)->getJson("/api/v1/teams/{$this->foreign->id}");
    $missing = $this->actingAs($this->user)->getJson('/api/v1/teams/'.Str::uuid7());

    $foreign->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $missing->assertNotFound()->assertJsonPath('error.code', 'not_found');

    expect($foreign->json('error.message'))->toBe($missing->json('error.message'))
        ->and($foreign->getContent())->not->toContain('Secret Corp')
        ->and($foreign->getContent())->not->toContain($this->foreign->id);
});

it('answers malformed team IDs with 404, not a database error', function (string $id) {
    $this->actingAs($this->user)
        ->getJson('/api/v1/teams/'.rawurlencode($id))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
})->with(['not-a-uuid', '1', "' OR 1=1 --", 'secret-corp']);

it('hides teams in the trash and keeps archived teams readable', function () {
    $this->joined->update(['archived_at' => now()]);
    $this->owned->delete();

    $this->actingAs($this->user)
        ->getJson('/api/v1/teams')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->joined->id)
        ->assertJsonPath('data.0.archived_at', $this->joined->archived_at?->utc()->toIso8601ZuluString());

    $this->actingAs($this->user)->getJson("/api/v1/teams/{$this->owned->id}")->assertNotFound();
});

it('stops showing a team as soon as the membership is removed', function () {
    TeamMembership::query()->where('team_id', $this->joined->id)->where('user_id', $this->user->id)->delete();

    $this->actingAs($this->user)->getJson("/api/v1/teams/{$this->joined->id}")->assertNotFound();
    $this->actingAs($this->user)->getJson('/api/v1/teams')->assertJsonCount(1, 'data');
});

it('requires authentication', function (string $path) {
    $this->getJson($path)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
})->with(fn () => ['/api/v1/me/teams', '/api/v1/teams', '/api/v1/teams/'.Str::uuid7()]);

it('pages with a signed cursor and stable order for equal timestamps', function () {
    $user = User::factory()->create();
    $at = now()->subHour();
    $ids = collect(range(1, 5))->map(fn () => Team::factory()->ownedBy($user)->create(['created_at' => $at])->id)
        ->sortDesc()->values()->all();

    $seen = [];
    $cursor = null;

    do {
        $page = $this->actingAs($user)->getJson('/api/v1/teams?limit=2'.($cursor ? '&cursor='.urlencode($cursor) : ''))->assertOk();
        $seen = [...$seen, ...array_column($page->json('data'), 'id')];
        $cursor = $page->json('meta.next_cursor');
        expect($page->json('meta.has_more'))->toBe($cursor !== null);
    } while ($cursor !== null);

    expect($seen)->toBe($ids);
});

it('rejects tampered, expired and foreign cursors with 400', function (Closure $make) {
    $cursor = $make();

    $this->actingAs($this->user)
        ->getJson('/api/v1/teams?cursor='.urlencode($cursor))
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'bad_request');
})->with([
    'tampered' => fn () => substr(CursorPagination::encode(new Cursor(['id' => 'x']), 'api/v1/teams'), 0, -2).'xx',
    'expired' => fn () => CursorPagination::encode(new Cursor(['id' => 'x']), 'api/v1/teams', now()->subDays(2)->timestamp),
    'other endpoint' => fn () => CursorPagination::encode(new Cursor(['id' => 'x']), 'api/v1/me/teams'),
    'garbage' => fn () => 'not-a-cursor',
]);

it('validates the page size', function (string $limit) {
    $this->actingAs($this->user)
        ->getJson("/api/v1/teams?limit={$limit}")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['fields' => ['limit']]]);
})->with(['0', '101', 'ten']);
