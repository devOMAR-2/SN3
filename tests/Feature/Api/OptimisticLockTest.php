<?php

use App\Http\Concurrency\IfMatch;
use App\Http\Responses\ApiResponse;
use App\Support\Concurrency\OptimisticLock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\VersionedRecord;

// FND-005 / API_SPEC.md §1.4, DATABASE.md §20: `version` + If-Match for versioned resources.

uses(RefreshDatabase::class);

beforeEach(function () {
    // PostgreSQL DDL is transactional: this table disappears with the test transaction.
    Schema::create('versioned_records', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('title');
        $table->bigInteger('version')->default(1);
        $table->timestampsTz();
    });

    Route::middleware(['web'])->patch('/api/v1/_test/versioned/{id}', function (Request $request, string $id) {
        $record = VersionedRecord::query()->findOrFail($id);
        $title = $request->string('title')->toString();

        $record = OptimisticLock::update($record, IfMatch::version($request), fn (VersionedRecord $r) => $r->title = $title);

        return ApiResponse::data(['id' => $record->id, 'title' => $record->title, 'version' => $record->version]);
    });

    $this->record = VersionedRecord::query()->create(['title' => 'Draft']);
});

it('starts versions at 1', function () {
    expect($this->record->refresh()->version)->toBe(1);
});

it('applies a change for the current version and increments it', function () {
    $this->patchJson("/api/v1/_test/versioned/{$this->record->id}", ['title' => 'Final'], ['If-Match' => '"1"'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Final')
        ->assertJsonPath('data.version', 2);

    expect($this->record->refresh())->title->toBe('Final')->version->toBe(2);
});

it('rejects a stale version with 412 and changes nothing', function () {
    $this->record->forceFill(['version' => 3])->save();

    $this->patchJson("/api/v1/_test/versioned/{$this->record->id}", ['title' => 'Final'], ['If-Match' => '"2"'])
        ->assertStatus(412)
        ->assertJsonPath('error.code', 'version_mismatch');

    expect($this->record->refresh())->title->toBe('Draft')->version->toBe(3);
});

it('requires If-Match with 428', function () {
    $this->patchJson("/api/v1/_test/versioned/{$this->record->id}", ['title' => 'Final'])
        ->assertStatus(428)
        ->assertJsonPath('error.code', 'precondition_required');

    expect($this->record->refresh()->title)->toBe('Draft');
});

it('treats an If-Match value that is not a version as a mismatch', function (string $header) {
    $this->patchJson("/api/v1/_test/versioned/{$this->record->id}", ['title' => 'Final'], ['If-Match' => $header])
        ->assertStatus(412)
        ->assertJsonPath('error.code', 'version_mismatch');
})->with(['1', '"0"', '"abc"', 'W/"1"', '*', '"1", "2"']);

it('keeps the version when the change is a no-op', function () {
    $this->patchJson("/api/v1/_test/versioned/{$this->record->id}", ['title' => 'Draft'], ['If-Match' => '"1"'])
        ->assertOk()
        ->assertJsonPath('data.version', 1);
});
