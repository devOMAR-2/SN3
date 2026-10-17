<?php

require_once __DIR__.'/../Collaboration/CollaborationHelpers.php';

use App\Domain\Activity\Activity;
use App\Domain\Exports\Sections\ActivitySection;
use App\Domain\Exports\Sections\AttachmentsSection;
use App\Domain\Exports\Sections\CommentsSection;
use App\Domain\Exports\TeamExportContext;
use App\Domain\Exports\ZipStreamWriter;
use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Http\Resources\TeamExportResource;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Models\Team;
use App\Models\TeamExport;
use App\Support\Tenancy\AccessibleProjects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// CORE-008: comments, the activity log and attachments in the team export (API_SPEC.md §13 "Include
// metadata, tasks, comments, custom fields and authorized attachments"; DATABASE.md §13 "audit
// metadata, plus attachment manifests and signed downloadable attachment archive"; ADR-024 §2, §8;
// ADR-027 §7). Same scoping as every section: the projects the requesting Owner can see, live tasks
// only, explicit field allowlists. Acceptance scenario AUTH-02 (export).

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('exports');
    Storage::fake('attachments');
    $this->workRoot = sys_get_temp_dir().'/sn3-export-work-test-'.Str::random(8);
    config(['sn3.exports.work_dir' => $this->workRoot]);

    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->member = taskTeamMember($this->team, TeamRole::Member);
    $this->admin = taskTeamMember($this->team, TeamRole::Admin);
    $this->public = taskProject($this->team);
    $this->private = taskProject($this->team, 'private');
    taskProjectMember($this->private, $this->member, ProjectRole::Manager);
});

afterEach(function () {
    File::deleteDirectory($this->workRoot);
});

/**
 * @return array<string, string> entry name => contents
 */
function collabExportEntries(object $test): array
{
    $id = $test->actingAs($test->owner)->postJson("/api/v1/teams/{$test->team->id}/exports", [], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(202)->json('data.id');
    $response = $test->actingAs($test->owner)->get(TeamExportResource::downloadUrl(TeamExport::query()->findOrFail($id)))->assertOk();

    $path = tempnam(sys_get_temp_dir(), 'sn3-test-export');
    file_put_contents($path, $response->streamedContent());
    $archive = new ZipArchive;
    expect($archive->open($path, ZipArchive::CHECKCONS))->toBeTrue();

    $entries = [];
    for ($i = 0; $i < $archive->numFiles; $i++) {
        $entries[(string) $archive->getNameIndex($i)] = (string) $archive->getFromIndex($i);
    }

    $archive->close();
    unlink($path);

    return $entries;
}

it('exports live comments with their mentions, the activity log, and attachments with their files', function () {
    $privateTask = taskIn($this->private, ['title' => 'Secret task']);
    $publicTask = taskIn($this->public);
    $comment = TaskComment::factory()->for($privateTask)->by($this->member)->create(['body' => 'تعليق **سري**']);
    DB::table('task_comment_mentions')->insert(['comment_id' => $comment->id, 'user_id' => $this->owner->id, 'created_at' => now()]);
    $deleted = TaskComment::factory()->for($privateTask)->create(['body' => 'Deleted words']);
    $deleted->delete();
    $pdf = TaskAttachment::factory()->for($privateTask)->by($this->member)->withContent(collabFixture('pdf'), '../خطة.pdf', 'application/pdf')->create();
    $png = TaskAttachment::factory()->for($publicTask)->withContent(collabFixture('png'), 'shot.png', 'image/png')->create();
    $removing = TaskAttachment::factory()->for($publicTask)->create();
    $removing->forceFill(['deleted_at' => now()])->save();
    $entry = Activity::task($privateTask, $this->member, 'task.updated', ['fields' => ['priority'], 'priority' => ['from' => 'none', 'to' => 'high']]);
    $teamEntry = Activity::team($this->team->id, $this->owner, 'team.member_role_changed', ['user_id' => $this->member->id, 'from' => 'viewer', 'to' => 'member']);

    // In the task trash: left out with its comments, activity and attachments.
    $trashedTask = taskIn($this->private);
    TaskComment::factory()->for($trashedTask)->create(['body' => 'Binned comment']);
    $binnedFile = TaskAttachment::factory()->for($trashedTask)->withContent('binned bytes')->create();
    Activity::task($trashedTask, $this->member, 'task.trashed');
    $trashedTask->delete();

    $entries = collabExportEntries($this);
    $manifest = collect(json_decode($entries['manifest.json'], true, flags: JSON_THROW_ON_ERROR)['sections'])->keyBy('name');
    $data = fn (string $name) => collect(json_decode($entries["data/{$name}.json"], true, flags: JSON_THROW_ON_ERROR));

    foreach (['comments' => 1, 'activity' => 2, 'attachments' => 2] as $name => $count) {
        expect($manifest[$name])->toMatchArray(['file' => "data/{$name}.json", 'records' => $count])
            ->and($manifest[$name]['sha256'])->toBe(hash('sha256', $entries["data/{$name}.json"]));
    }

    expect($data('comments')->sole())->toBe([
        'id' => $comment->id,
        'task_id' => $privateTask->id,
        'author_user_id' => $this->member->id,
        'body' => 'تعليق **سري**',
        'mention_user_ids' => [$this->owner->id],
        'edited_at' => null,
        'created_at' => $comment->created_at->utc()->toIso8601ZuluString(),
        'updated_at' => $comment->updated_at->utc()->toIso8601ZuluString(),
    ]);

    $activity = $data('activity')->keyBy('id');
    expect($activity->keys()->all())->toEqualCanonicalizing([$entry->id, $teamEntry->id])
        ->and(array_keys($activity[$entry->id]))->toBe(['id', 'project_id', 'task_id', 'actor_user_id', 'action', 'changes', 'source', 'created_at'])
        ->and($activity[$entry->id])->toMatchArray(['project_id' => $this->private->id, 'task_id' => $privateTask->id, 'action' => 'task.updated'])
        ->and($activity[$entry->id]['changes'])->toEqual(['fields' => ['priority'], 'priority' => ['from' => 'none', 'to' => 'high']])
        ->and($activity[$teamEntry->id])->toMatchArray(['project_id' => null, 'task_id' => null, 'action' => 'team.member_role_changed']);

    $attachments = $data('attachments')->keyBy('id');
    expect($attachments->keys()->all())->toEqualCanonicalizing([$pdf->id, $png->id])
        ->and(array_keys($attachments[$pdf->id]))->toBe(['id', 'task_id', 'uploaded_by', 'filename', 'mime_type', 'size_bytes', 'sha256', 'file', 'created_at'])
        ->and($attachments[$pdf->id])->toMatchArray([
            'filename' => '../خطة.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen(collabFixture('pdf')),
            'sha256' => hash('sha256', collabFixture('pdf')),
            'file' => "files/{$pdf->id}",
        ]);

    // The files themselves, under their IDs only (never their names), byte for byte.
    expect($entries["files/{$pdf->id}"])->toBe(collabFixture('pdf'))
        ->and($entries["files/{$png->id}"])->toBe(collabFixture('png'))
        ->and(hash('sha256', $entries["files/{$png->id}"]))->toBe($attachments[$png->id]['sha256'])
        ->and(collect(array_keys($entries))->filter(fn (string $name) => str_starts_with($name, 'files/'))->values()->all())->toHaveCount(2);

    $all = implode("\n", $entries);
    foreach (['Deleted words', 'Binned comment', 'binned bytes', $removing->id, $binnedFile->id, $trashedTask->id, 'objects/', 'storage_key', 'storage_disk'] as $absent) {
        expect($all)->not->toContain($absent);
    }
});

it('records a missing file as such instead of failing the export', function () {
    $attachment = TaskAttachment::factory()->for(taskIn($this->public))->create();
    Storage::disk('attachments')->delete($attachment->storage_key);

    $entries = collabExportEntries($this);

    expect(json_decode($entries['data/attachments.json'], true, flags: JSON_THROW_ON_ERROR)[0]['file'])->toBeNull()
        ->and(array_key_exists("files/{$attachment->id}", $entries))->toBeFalse();
});

it('scopes collaboration sections through the projects the requester can see (DB-T02)', function () {
    $privateTask = taskIn($this->private);
    TaskComment::factory()->for($privateTask)->create();
    TaskAttachment::factory()->for($privateTask)->create();
    Activity::task($privateTask, $this->member, 'task.trashed');
    $publicTask = taskIn($this->public);
    $publicComment = TaskComment::factory()->for($publicTask)->create();
    $foreignTeam = Team::factory()->create();
    TaskComment::factory()->for(taskIn(taskProject($foreignTeam)))->create();

    // A context for a Team Admin outside the private project (an export is Owner-only; the scoping
    // itself must hold for anyone).
    $context = new TeamExportContext($this->team, $this->admin, new TeamExport, app(AccessibleProjects::class));
    $records = fn (string $class) => collect(iterator_to_array(app($class)->records($context), false));

    expect($records(CommentsSection::class)->pluck('id')->all())->toBe([$publicComment->id])
        ->and($records(AttachmentsSection::class)->all())->toBe([])
        ->and($records(ActivitySection::class)->all())->toBe([]);
});

it('writes zip archives that standard readers accept, deflating data and storing files', function () {
    $dir = sys_get_temp_dir().'/sn3-zip-'.Str::random(8);
    mkdir($dir);
    $json = $dir.'/data.json';
    file_put_contents($json, str_repeat('{"name":"مشروع"}', 5000));
    $binary = random_bytes(3 * 1024 * 1024 + 7);
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $binary);
    rewind($stream);

    $zip = new ZipStreamWriter($dir.'/out.zip', now());
    $zip->addFile('data/x.json', $json, deflate: true);
    $zip->addStream('files/'.Str::uuid7(), $stream, deflate: false);
    $zip->addStream('empty.json', fopen('php://memory', 'rb'), deflate: true);
    $zip->close();

    $archive = new ZipArchive;
    expect($archive->open($dir.'/out.zip', ZipArchive::CHECKCONS))->toBeTrue()
        ->and($archive->numFiles)->toBe(3)
        ->and($archive->getFromIndex(0))->toBe(file_get_contents($json))
        ->and($archive->statIndex(0)['comp_method'])->toBe(ZipArchive::CM_DEFLATE)
        ->and($archive->statIndex(0)['comp_size'])->toBeLessThan(filesize($json))
        ->and($archive->getFromIndex(1))->toBe($binary)
        ->and($archive->statIndex(1)['comp_method'])->toBe(ZipArchive::CM_STORE)
        ->and($archive->statIndex(1)['crc'])->toBe(crc32($binary))
        ->and($archive->getFromIndex(2))->toBe('');
    $archive->close();

    File::deleteDirectory($dir);
});

it('writes ZIP64 end records for more than 65,535 entries (ADR-027 §7)', function () {
    $dir = sys_get_temp_dir().'/sn3-zip-'.Str::random(8);
    mkdir($dir);
    $zip = new ZipStreamWriter($dir.'/out.zip', now());
    $empty = fopen('php://memory', 'rb');

    try {
        for ($i = 0; $i < 70_000; $i++) {
            $zip->addStream("e{$i}", $empty, deflate: false, size: 0);
        }
        $zip->close();

        $raw = (string) file_get_contents($dir.'/out.zip');
        expect($raw)->toContain(pack('V', 0x06064B50))->toContain(pack('V', 0x07064B50));

        $archive = new ZipArchive;
        expect($archive->open($dir.'/out.zip', ZipArchive::CHECKCONS))->toBeTrue()
            ->and($archive->numFiles)->toBe(70_000)
            ->and($archive->getNameIndex(69_999))->toBe('e69999');
        $archive->close();
    } finally {
        File::deleteDirectory($dir);
    }
});

it('writes valid ZIP64 sizes and offsets, which standard readers accept', function () {
    $dir = sys_get_temp_dir().'/sn3-zip-'.Str::random(8);
    mkdir($dir);
    $json = $dir.'/data.json';
    file_put_contents($json, str_repeat('{"name":"مشروع"}', 3000));
    $binary = random_bytes(200_000);
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $binary);
    rewind($stream);
    $unknown = fopen('php://temp', 'w+b');
    fwrite($unknown, 'size not declared');
    rewind($unknown);

    // A threshold of 0 puts every size, offset and the end of the directory into ZIP64 fields, which
    // a real archive only needs beyond 4 GiB.
    $zip = new ZipStreamWriter($dir.'/out.zip', now(), zip64Threshold: 0);
    $zip->addFile('data/x.json', $json, deflate: true);
    $zip->addStream('files/one', $stream, deflate: false, size: strlen($binary));
    $zip->addStream('files/two', $unknown, deflate: true);
    $zip->close();

    $archive = new ZipArchive;
    expect($archive->open($dir.'/out.zip', ZipArchive::CHECKCONS))->toBeTrue()
        ->and($archive->numFiles)->toBe(3)
        ->and($archive->getFromName('data/x.json'))->toBe(file_get_contents($json))
        ->and($archive->getFromName('files/one'))->toBe($binary)
        ->and($archive->statName('files/one')['crc'])->toBe(crc32($binary))
        ->and($archive->getFromName('files/two'))->toBe('size not declared');
    $archive->close();

    File::deleteDirectory($dir);
});
