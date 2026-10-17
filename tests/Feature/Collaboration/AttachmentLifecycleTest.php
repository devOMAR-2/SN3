<?php

require_once __DIR__.'/CollaborationHelpers.php';

use App\Domain\Attachments\AttachmentStorage;
use App\Domain\Attachments\SweepAttachments;
use App\Domain\Teams\TeamRole;
use App\Domain\Trash\PurgeTrash;
use App\Models\TaskAttachment;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// CORE-008: stored attachment files never outlive what needs them (ADR-022 §5 "stored files are not
// rows: their tasks must delete them along with the purge"; DATABASE.md §13; ADR-027 §5). Deleting an
// attachment removes its file; the trash purge removes the files of purged projects and teams; the
// hourly `attachments:sweep` finishes what an outage or a crash left. DEL-02 (files).

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->member = taskTeamMember($this->team, TeamRole::Member);
    $this->project = taskProject($this->team);
    $this->task = taskIn($this->project);
});

it('deletes the files of a purged project, and only those', function () {
    $purged = taskProject($this->team);
    $gone = TaskAttachment::factory()->for(taskIn($purged))->create();
    $trashedTaskFile = TaskAttachment::factory()->for(tap(taskIn($purged))->delete())->create();
    $kept = TaskAttachment::factory()->for($this->task)->create();
    $purged->forceFill(['deleted_at' => now()->subDays(31)])->save();

    expect(app(PurgeTrash::class)->handle())->toBe(['projects' => 1, 'teams' => 0, 'failed' => 0]);

    expect(Storage::disk('attachments')->exists($gone->storage_key))->toBeFalse()
        ->and(Storage::disk('attachments')->exists($trashedTaskFile->storage_key))->toBeFalse()
        ->and(Storage::disk('attachments')->directoryExists(app(AttachmentStorage::class)->projectPrefix($this->team->id, $purged->id)))->toBeFalse()
        ->and(collabStoredFiles())->toBe([$kept->storage_key])
        ->and(TaskAttachment::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('deletes every file of a purged team, and leaves other teams alone', function () {
    TaskAttachment::factory()->for($this->task)->create();
    TaskAttachment::factory()->for(taskIn(taskProject($this->team, 'private')))->create();
    $otherTeam = Team::factory()->create();
    $foreign = TaskAttachment::factory()->for(taskIn(taskProject($otherTeam)))->create();
    $this->team->forceFill(['deleted_at' => now()->subDays(30)])->save();

    expect(app(PurgeTrash::class)->handle())->toBe(['projects' => 0, 'teams' => 1, 'failed' => 0]);

    expect(collabStoredFiles())->toBe([$foreign->storage_key])
        ->and(Storage::disk('attachments')->directoryExists('objects/'.$this->team->id))->toBeFalse();
});

it('keeps the files of trashed projects and teams within the 30 days, so recovery brings them back', function () {
    $attachment = TaskAttachment::factory()->for($this->task)->create();
    $this->project->forceFill(['deleted_at' => now()->subDays(29)])->save();

    app(PurgeTrash::class)->handle();
    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/restore-deleted")->assertOk();

    $this->actingAs($this->member)->get("/api/v1/attachments/{$attachment->id}/download")->assertOk();
});

it('finishes a removal the storage could not complete', function () {
    $attachment = TaskAttachment::factory()->for($this->task)->by($this->member)->create();

    // The storage fails while the request deletes the file: the row stays marked, invisible and out of the quota.
    $broken = Mockery::mock(Storage::disk('attachments'))->makePartial();
    $broken->shouldReceive('delete')->andThrow(new RuntimeException('Storage unavailable'));
    Storage::set('attachments', $broken);

    $this->actingAs($this->member)->deleteJson("/api/v1/attachments/{$attachment->id}")->assertNoContent();

    expect(TaskAttachment::query()->whereKey($attachment->id)->whereNotNull('deleted_at')->exists())->toBeTrue()
        ->and(TaskAttachment::query()->live()->count())->toBe(0);
    $this->actingAs($this->member)->get("/api/v1/attachments/{$attachment->id}/download", ['Accept' => 'application/json'])->assertNotFound();
    $this->actingAs($this->member)->getJson("/api/v1/tasks/{$this->task->id}/attachments")->assertJsonCount(0, 'data');

    Storage::fake('attachments');
    Storage::disk('attachments')->put($attachment->storage_key, 'still there');

    $this->artisan('attachments:sweep')->expectsOutputToContain('Finished 1 pending removal(s)')->assertSuccessful();

    expect(TaskAttachment::query()->whereKey($attachment->id)->exists())->toBeFalse()
        ->and(collabStoredFiles())->toBe([]);
});

it('sweeps the files of purged projects and teams that are still there', function () {
    $storage = app(AttachmentStorage::class);
    $kept = TaskAttachment::factory()->for($this->task)->create();
    $goneProject = (string) Str::uuid7();
    $goneTeam = (string) Str::uuid7();
    Storage::disk('attachments')->put($storage->newKey($this->team->id, $goneProject), 'left behind');
    Storage::disk('attachments')->put($storage->newKey($goneTeam, (string) Str::uuid7()), 'left behind');

    expect(app(SweepAttachments::class)->handle())->toBe(['pending' => 0, 'prefixes' => 2, 'orphans' => 0])
        ->and(collabStoredFiles())->toBe([$kept->storage_key]);
});

it('deletes orphaned files after a grace period, never a file whose upload may still commit', function () {
    $storage = app(AttachmentStorage::class);
    $kept = TaskAttachment::factory()->for($this->task)->create();
    $orphan = $storage->newKey($this->team->id, $this->project->id);
    Storage::disk('attachments')->put($orphan, 'rolled back');
    // Not ours: never touched.
    Storage::disk('attachments')->put('objects/readme.txt', 'operator note');
    Storage::disk('attachments')->put('elsewhere/file', 'other data');

    expect(app(SweepAttachments::class)->handle()['orphans'])->toBe(0);

    $this->travel(SweepAttachments::ORPHAN_GRACE_MINUTES + 1)->minutes();
    touch(Storage::disk('attachments')->path($kept->storage_key));

    expect(app(SweepAttachments::class)->handle())->toBe(['pending' => 0, 'prefixes' => 0, 'orphans' => 1])
        ->and(collabStoredFiles())->toEqualCanonicalizing([$kept->storage_key, 'objects/readme.txt', 'elsewhere/file']);
});

it('purges and sweeps attachment files on every disk they were written to', function () {
    $oldDisk = ['driver' => 'local', 'root' => storage_path('framework/testing/disks/attachments-old'), 'throw' => true];
    config(['filesystems.disks.attachments-old' => $oldDisk, 'filesystems.disks.attachments-older' => $oldDisk]);
    Storage::fake('attachments-old');
    Storage::fake('attachments-older');
    $storage = app(AttachmentStorage::class);

    // Written before SN3_ATTACHMENTS_DISK changed: the row keeps its disk.
    $purged = taskProject($this->team);
    $onOldDisk = TaskAttachment::factory()->for(taskIn($purged))->create(['storage_disk' => 'attachments-old']);
    $keptOnOldDisk = TaskAttachment::factory()->for($this->task)->create(['storage_disk' => 'attachments-old']);
    $purged->forceFill(['deleted_at' => now()->subDays(31)])->save();

    expect($storage->diskNames())->toEqualCanonicalizing(['attachments', 'attachments-old']);

    app(PurgeTrash::class)->handle();

    expect(Storage::disk('attachments-old')->exists($onOldDisk->storage_key))->toBeFalse()
        ->and(Storage::disk('attachments-old')->exists($keptOnOldDisk->storage_key))->toBeTrue();

    // A disk no row names any more is cleaned only once the operator lists it.
    $leftover = $storage->newKey(Str::uuid7()->toString(), Str::uuid7()->toString());
    Storage::disk('attachments-older')->put($leftover, 'left behind');
    // An orphan on the old disk whose key a row of another disk uses is still an orphan there.
    Storage::disk('attachments-old')->put(TaskAttachment::factory()->for($this->task)->create()->storage_key, 'stray copy');

    app(SweepAttachments::class)->handle();
    expect(Storage::disk('attachments-older')->exists($leftover))->toBeTrue();

    config(['sn3.attachments.previous_disks' => ['attachments-older', 'not-a-disk']]);
    $this->travel(SweepAttachments::ORPHAN_GRACE_MINUTES + 1)->minutes();

    expect(app(SweepAttachments::class)->handle())->toBe(['pending' => 0, 'prefixes' => 1, 'orphans' => 1])
        ->and(Storage::disk('attachments-older')->allFiles())->toBe([])
        ->and(Storage::disk('attachments-old')->allFiles())->toBe([$keptOnOldDisk->storage_key]);
});

it('keeps attachment files in place when a task is trashed and restored', function () {
    $attachment = TaskAttachment::factory()->for($this->task)->create();
    $this->actingAs($this->owner)->deleteJson("/api/v1/tasks/{$this->task->id}", [], taskIfMatch(1))->assertNoContent();

    $this->actingAs($this->owner)->get("/api/v1/attachments/{$attachment->id}/download", ['Accept' => 'application/json'])->assertNotFound();
    $this->artisan('attachments:sweep')->assertSuccessful();
    expect(collabStoredFiles())->toBe([$attachment->storage_key]);

    $this->actingAs($this->owner)->postJson("/api/v1/tasks/{$this->task->id}/restore")->assertOk();
    $this->actingAs($this->owner)->get("/api/v1/attachments/{$attachment->id}/download")->assertOk();
});

it('never reaches a remote service while purging attachment files', function () {
    Http::preventStrayRequests();
    TaskAttachment::factory()->for($this->task)->create();
    $this->team->forceFill(['deleted_at' => now()->subDays(31)])->save();

    $this->artisan('trash:purge')->assertSuccessful();

    expect(collabStoredFiles())->toBe([])->and(DB::table('task_attachments')->count())->toBe(0);
});
