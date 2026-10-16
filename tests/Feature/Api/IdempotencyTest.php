<?php

use App\Http\Responses\ApiResponse;
use App\Models\ApiIdempotencyRecord;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// FND-005 / API_SPEC.md §1.4, DATABASE.md §20: Idempotency-Key on retry-safe creates.

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('idempotency_probe', function (Blueprint $table) {
        $table->id();
        $table->string('title');
    });

    // A create endpoint shaped like the real ones (team-scoped, behind auth and `idempotent`).
    Route::middleware(['web', 'auth', 'idempotent'])->post('/api/v1/teams/{team}/_probe', function (Request $request, Team $team) {
        if ($request->input('title') === 'invalid') {
            DB::table('idempotency_probe')->insert(['title' => 'partial write']);

            throw ValidationException::withMessages(['title' => 'invalid']);
        }

        $id = DB::table('idempotency_probe')->insertGetId(['title' => (string) $request->input('title')]);

        return ApiResponse::data(['probe_id' => $id, 'team_id' => $team->id, 'title' => $request->input('title')], 201);
    });

    $this->team = Team::factory()->create();
    $this->user = $this->team->owner;
    $this->url = "/api/v1/teams/{$this->team->id}/_probe";
    $this->key = (string) Str::uuid();
});

function probeCount(): int
{
    return DB::table('idempotency_probe')->count();
}

it('runs the request once and replays the stored result for the same key and payload', function () {
    $first = $this->actingAs($this->user)
        ->postJson($this->url, ['title' => 'مهمة', 'tags' => ['a', 'b']], ['Idempotency-Key' => $this->key])
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    $replay = $this->actingAs($this->user)
        ->postJson($this->url, ['tags' => ['a', 'b'], 'title' => 'مهمة'], ['Idempotency-Key' => strtoupper($this->key)])
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($replay->json())->toEqual($first->json())
        ->and(probeCount())->toBe(1);

    $record = ApiIdempotencyRecord::query()->sole();
    expect($record->team_id)->toBe($this->team->id)
        ->and($record->route_key)->toBe("POST /api/v1/teams/{$this->team->id}/_probe")
        ->and($record->expires_at->between(now()->addHours(23), now()->addHours(25)))->toBeTrue();
});

it('rejects the same key with a different payload with 409 and does not run it', function () {
    $this->actingAs($this->user)->postJson($this->url, ['title' => 'One'], ['Idempotency-Key' => $this->key])->assertCreated();

    $this->actingAs($this->user)
        ->postJson($this->url, ['title' => 'Two'], ['Idempotency-Key' => $this->key])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_reused');

    expect(probeCount())->toBe(1);
});

it('requires a UUID key', function (?string $key) {
    $headers = $key === null ? [] : ['Idempotency-Key' => $key];

    $this->actingAs($this->user)
        ->postJson($this->url, ['title' => 'One'], $headers)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'bad_request');

    expect(probeCount())->toBe(0);
})->with([null, '', 'abc', '12345']);

it('does not store failed requests and rolls back their writes, so the key stays usable', function () {
    $this->actingAs($this->user)
        ->postJson($this->url, ['title' => 'invalid'], ['Idempotency-Key' => $this->key])
        ->assertUnprocessable();

    expect(probeCount())->toBe(0)
        ->and(ApiIdempotencyRecord::query()->count())->toBe(0);

    $this->actingAs($this->user)
        ->postJson($this->url, ['title' => 'fixed'], ['Idempotency-Key' => $this->key])
        ->assertCreated();

    expect(probeCount())->toBe(1);
});

it('scopes keys per actor', function () {
    $other = User::factory()->create();
    $this->team->memberships()->forceCreate(['user_id' => $other->id, 'role' => 'admin', 'joined_at' => now()]);

    $this->actingAs($this->user)->postJson($this->url, ['title' => 'One'], ['Idempotency-Key' => $this->key])->assertCreated();
    $this->actingAs($other)->postJson($this->url, ['title' => 'One'], ['Idempotency-Key' => $this->key])
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(probeCount())->toBe(2);
});

it('scopes keys per tenant and endpoint', function () {
    $second = Team::factory()->ownedBy($this->user)->create();

    $this->actingAs($this->user)->postJson($this->url, ['title' => 'One'], ['Idempotency-Key' => $this->key])->assertCreated();
    $this->actingAs($this->user)
        ->postJson("/api/v1/teams/{$second->id}/_probe", ['title' => 'One'], ['Idempotency-Key' => $this->key])
        ->assertCreated()
        ->assertJsonPath('data.team_id', $second->id);

    expect(probeCount())->toBe(2);
});

it('treats an expired key as new', function () {
    $this->actingAs($this->user)->postJson($this->url, ['title' => 'One'], ['Idempotency-Key' => $this->key])->assertCreated();

    $this->travel(25)->hours();

    $this->actingAs($this->user)
        ->postJson($this->url, ['title' => 'Two'], ['Idempotency-Key' => $this->key])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Two');

    expect(probeCount())->toBe(2)
        ->and(ApiIdempotencyRecord::query()->count())->toBe(1);
});

it('never stores anything for a team the caller cannot see', function () {
    $foreign = Team::factory()->create();

    $this->actingAs($this->user)
        ->postJson("/api/v1/teams/{$foreign->id}/_probe", ['title' => 'One'], ['Idempotency-Key' => $this->key])
        ->assertNotFound();

    expect(probeCount())->toBe(0)
        ->and(ApiIdempotencyRecord::query()->count())->toBe(0);
});

it('prunes records once they expire', function () {
    $this->actingAs($this->user)->postJson($this->url, ['title' => 'One'], ['Idempotency-Key' => $this->key])->assertCreated();

    $this->artisan('model:prune', ['--model' => [ApiIdempotencyRecord::class]])->assertSuccessful();
    expect(ApiIdempotencyRecord::query()->count())->toBe(1);

    $this->travel(25)->hours();
    $this->artisan('model:prune', ['--model' => [ApiIdempotencyRecord::class]])->assertSuccessful();
    expect(ApiIdempotencyRecord::query()->count())->toBe(0);
});

it('keeps and replays successful responses without a body', function () {
    Route::middleware(['web', 'auth', 'idempotent'])->delete('/api/v1/teams/{team}/_probe', function (Team $team) {
        DB::table('idempotency_probe')->insert(['title' => 'deleted marker']);

        return response()->noContent();
    });

    $this->actingAs($this->user)->deleteJson($this->url, [], ['Idempotency-Key' => $this->key])->assertNoContent();
    $this->actingAs($this->user)->deleteJson($this->url, [], ['Idempotency-Key' => $this->key])
        ->assertNoContent()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect(probeCount())->toBe(1);
});
