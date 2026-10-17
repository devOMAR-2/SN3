<?php

require_once __DIR__.'/CollaborationHelpers.php';

use App\Domain\Attachments\AttachmentFilename;
use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\TaskActivity;
use App\Models\TaskAttachment;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// CORE-008: task attachments (API_SPEC.md §6.3, §13; DATABASE.md §5 task_attachments; DECIDED-004;
// AGENTS.md §8 "Attachments: size/type/path controls, private downloads, quota exhaustion").
// Upload to a private disk under random keys; sniffed types against an allowlist; safe names;
// authorized, re-checked downloads that are never rendered inline; 25 MiB per file and 2 GiB per team
// (configurable); deletion removes the file. Acceptance scenarios LIM-01, LIM-02, AUTH-04, AUTH-05;
// DATABASE.md §16 DB-T01, DB-T02, DB-T08.

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->member = taskTeamMember($this->team, TeamRole::Member);
    $this->other = taskTeamMember($this->team, TeamRole::Member);
    $this->admin = taskTeamMember($this->team, TeamRole::Admin);
    $this->viewer = taskTeamMember($this->team, TeamRole::Viewer);
    $this->project = taskProject($this->team);
    $this->task = taskIn($this->project);
});

// --- Upload, list, download ----------------------------------------------------------------------

it('stores an upload on the private disk under a random key, with the sniffed type, size and digest', function () {
    $this->freezeTime();
    $content = collabFixture('pdf');

    $response = collabUploadTo($this->member, $this->task, collabUpload($content, 'Q3 خطة.pdf', 'text/html'))
        ->assertCreated()
        ->assertJsonPath('data.task_id', $this->task->id)
        ->assertJsonPath('data.uploaded_by', $this->member->id)
        ->assertJsonPath('data.filename', 'Q3 خطة.pdf')
        // The client's Content-Type (text/html) is ignored; the content decides.
        ->assertJsonPath('data.mime_type', 'application/pdf')
        ->assertJsonPath('data.size_bytes', strlen($content))
        ->assertJsonPath('data.sha256', hash('sha256', $content))
        ->assertJsonPath('data.created_at', now()->utc()->toIso8601ZuluString());

    $id = $response->json('data.id');
    expect(array_keys($response->json('data')))->toBe(['id', 'task_id', 'uploaded_by', 'filename', 'mime_type', 'size_bytes', 'sha256', 'download_url', 'created_at'])
        ->and($response->json('data.download_url'))->toBe("/api/v1/attachments/{$id}/download")
        ->and($response->headers->get('Location'))->toBe("/api/v1/attachments/{$id}/download");

    $row = TaskAttachment::query()->sole();
    expect($row->storage_disk)->toBe('attachments')
        ->and($row->storage_key)->toMatch("#^objects/{$this->team->id}/{$this->project->id}/[0-9a-f]{32}$#")
        ->and($row->storage_key)->not->toContain('pdf')
        ->and(Storage::disk('attachments')->get($row->storage_key))->toBe($content)
        ->and(collabStoredFiles())->toBe([$row->storage_key])
        // Nothing reaches the public disk.
        ->and(array_diff(Storage::disk('public')->allFiles(), ['.gitignore']))->toBe([]);

    expect(TaskActivity::query()->where('action', 'attachment.added')->sole()->changes)
        ->toEqual(['attachment_id' => $id, 'mime_type' => 'application/pdf', 'size_bytes' => strlen($content)]);
});

it('lists metadata newest first for anyone who can see the task, never storage keys', function () {
    $older = TaskAttachment::factory()->for($this->task)->create(['created_at' => now()->subMinute()]);
    $newer = TaskAttachment::factory()->for($this->task)->create();

    $response = $this->actingAs($this->viewer)->getJson("/api/v1/tasks/{$this->task->id}/attachments")
        ->assertOk()
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id);

    expect(json_encode($response->json()))->not->toContain('objects/')->not->toContain('storage');
});

it('streams downloads through the authorized route as a sandboxed, never-sniffed attachment', function () {
    $attachment = TaskAttachment::factory()->for($this->task)->withContent(collabFixture('pdf'), 'تقرير "Q3"; final.pdf', 'application/pdf')->create();

    $response = $this->actingAs($this->viewer)->get("/api/v1/attachments/{$attachment->id}/download")->assertOk();

    expect($response->streamedContent())->toBe(collabFixture('pdf'))
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen(collabFixture('pdf')))
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Disposition'))
        ->toStartWith('attachment; filename=')
        ->toContain("filename*=utf-8''".rawurlencode('تقرير "Q3"; final.pdf'))
        ->not->toContain('inline');
});

// --- Size, quota and type controls (LIM-01, LIM-02) ---------------------------------------------------

it('refuses a file over 25 MiB on Cloud with 413, storing nothing (LIM-01)', function () {
    expect(config('sn3.attachments.max_bytes'))->toBe(25 * 1024 * 1024);
    $big = collabUpload(str_repeat('a', 25 * 1024 * 1024 + 1), 'big.txt', 'text/plain');

    collabUploadTo($this->member, $this->task, $big)->assertStatus(413)->assertJsonPath('error.code', 'payload_too_large');

    // Exactly 25 MiB is fine.
    collabUploadTo($this->member, $this->task, collabUpload(str_repeat('a', 25 * 1024 * 1024), 'ok.txt'))->assertCreated();

    expect(TaskAttachment::query()->count())->toBe(1)->and(collabStoredFiles())->toHaveCount(1);
});

it('measures the received file, not the client\'s claim, and honours a self-hosted limit', function () {
    config(['sn3.attachments.max_bytes' => 1024]);

    collabUploadTo($this->member, $this->task, collabUpload(str_repeat('a', 1025), 'a.txt'))->assertStatus(413);
    collabUploadTo($this->member, $this->task, collabUpload(str_repeat('a', 1024), 'a.txt'))->assertCreated();

    // 0 means no per-file limit (self-hosted only).
    config(['sn3.attachments.max_bytes' => 0]);
    collabUploadTo($this->member, $this->task, collabUpload(str_repeat('a', 4096), 'b.txt'))->assertCreated();
});

it('answers 413 when PHP itself refused the upload for its size', function () {
    $file = new UploadedFile(tempnam(sys_get_temp_dir(), 'sn3'), 'huge.pdf', null, UPLOAD_ERR_INI_SIZE, true);

    collabUploadTo($this->member, $this->task, $file)->assertStatus(413);
    expect(TaskAttachment::query()->count())->toBe(0);
});

it('refuses an upload that would exceed the team quota before storing anything (LIM-02)', function () {
    config(['sn3.attachments.team_quota_bytes' => 100]);
    // The quota is per team, across projects (and counts trashed tasks, whose files are still stored).
    $otherProjectTask = taskIn(taskProject($this->team));
    TaskAttachment::factory()->for($otherProjectTask)->withContent(str_repeat('x', 60))->create();
    $trashed = taskIn($this->project);
    TaskAttachment::factory()->for($trashed)->withContent(str_repeat('y', 30))->create();
    $trashed->delete();
    $before = collabStoredFiles();

    collabUploadTo($this->member, $this->task, collabUpload(str_repeat('a', 11), 'a.txt'))->assertStatus(413)->assertJsonPath('error.code', 'payload_too_large');

    expect(TaskAttachment::query()->count())->toBe(2)
        ->and(collabStoredFiles())->toBe($before);

    collabUploadTo($this->member, $this->task, collabUpload(str_repeat('a', 10), 'a.txt'))->assertCreated();

    // Another team's usage does not count.
    $otherTeam = Team::factory()->create();
    $foreignTask = taskIn(taskProject($otherTeam));
    collabUploadTo($otherTeam->owner, $foreignTask, collabUpload(str_repeat('a', 100), 'a.txt'))->assertCreated();

    // Deleting frees the space at once.
    $this->actingAs($this->owner)->deleteJson('/api/v1/attachments/'.TaskAttachment::query()->where('task_id', $otherProjectTask->id)->value('id'))->assertNoContent();
    collabUploadTo($this->member, $this->task, collabUpload(str_repeat('a', 60), 'b.txt'))->assertCreated();
});

it('uses a 2 GiB team quota on Cloud, and 0 disables it for self-hosted operators', function () {
    expect(config('sn3.attachments.team_quota_bytes'))->toBe(2 * 1024 * 1024 * 1024);

    // A team already holding 2 GiB (rows only; the quota sums stored sizes).
    TaskAttachment::factory()->for($this->task)->create();
    TaskAttachment::query()->update(['size_bytes' => 2 * 1024 * 1024 * 1024]);

    collabUploadTo($this->member, $this->task, collabUpload(collabFixture('text'), 'a.txt'))->assertStatus(413);

    config(['sn3.attachments.team_quota_bytes' => 0]);
    collabUploadTo($this->member, $this->task, collabUpload(collabFixture('text'), 'a.txt'))->assertCreated();
});

it('accepts allowed types whose name and content agree', function (string $kind, string $name, string $mime) {
    collabUploadTo($this->member, $this->task, collabUpload(collabFixture($kind), $name, 'application/octet-stream'))
        ->assertCreated()
        ->assertJsonPath('data.mime_type', $mime);
})->with([
    'png' => ['png', 'screen.PNG', 'image/png'],
    'pdf' => ['pdf', 'report.pdf', 'application/pdf'],
    'text' => ['text', 'notes.md', 'text/plain'],
    'zip' => ['zip', 'bundle.zip', 'application/zip'],
]);

it('refuses spoofed, active and unknown content (422 mimetypes), whatever the client claims', function (string $kind, string $name, string $claimed) {
    collabUploadTo($this->member, $this->task, collabUpload(collabFixture($kind), $name, $claimed))
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.file', ['mimetypes']);

    expect(TaskAttachment::query()->count())->toBe(0)->and(collabStoredFiles())->toBe([]);
})->with([
    'executable named as an image' => ['exe', 'cat.png', 'image/png'],
    'executable named as a PDF' => ['exe', 'invoice.pdf', 'application/pdf'],
    'executable as itself' => ['exe', 'setup.exe', 'application/x-msdownload'],
    'PDF named as an executable' => ['pdf', 'run.exe', 'application/pdf'],
    'HTML named as text' => ['html', 'notes.txt', 'text/plain'],
    'HTML' => ['html', 'page.html', 'text/html'],
    'SVG with script' => ['svg', 'logo.svg', 'image/svg+xml'],
    'PHP named as text' => ['php', 'shell.txt', 'text/plain'],
    'PHP' => ['php', 'shell.php', 'text/plain'],
    'unknown binary' => ['binary', 'data.bin', 'application/octet-stream'],
    'right type, no extension' => ['pdf', 'report', 'application/pdf'],
    'double extension ending in exe' => ['pdf', 'report.pdf.exe', 'application/pdf'],
]);

it('accepts polyglots only as what they sniff as, and never lets a browser run them', function (string $kind, string $name, string $mime) {
    $id = collabUploadTo($this->member, $this->task, collabUpload(collabFixture($kind), $name, 'text/html'))
        ->assertCreated()
        ->assertJsonPath('data.mime_type', $mime)
        ->json('data.id');

    $response = $this->actingAs($this->member)->get("/api/v1/attachments/{$id}/download")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe($mime)
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
})->with([
    'GIF that is also a script' => ['gif-polyglot', 'funny.gif', 'image/gif'],
    'PDF with a zip appended' => ['pdf-zip', 'contract.pdf', 'application/pdf'],
]);

it('refuses an empty file, a missing file and several files', function () {
    collabUploadTo($this->member, $this->task, collabUpload('', 'empty.txt'))->assertUnprocessable()->assertJsonPath('error.fields.file', ['min']);
    collabUploadTo($this->member, $this->task, null)->assertUnprocessable()->assertJsonPath('error.fields.file', ['required']);
    collabUploadTo($this->member, $this->task, [collabUpload('a', 'a.txt'), collabUpload('b', 'b.txt')])->assertUnprocessable()->assertJsonPath('error.fields.file.0', 'file');
    collabUploadTo($this->member, $this->task, collabUpload('a', 'a.txt'), extra: ['task_id' => $this->task->id])->assertUnprocessable()->assertJsonPath('error.fields.task_id', ['unknown_field']);
    collabUploadTo($this->member, $this->task, collabUpload('a', 'a.txt'), extra: ['other' => collabUpload('b', 'b.txt')])->assertUnprocessable()->assertJsonPath('error.fields.other', ['unknown_field']);

    expect(TaskAttachment::query()->count())->toBe(0)->and(collabStoredFiles())->toBe([]);
});

// --- File names ---------------------------------------------------------------------------------------

it('keeps only a safe display name: no paths, control or bidi characters', function (string $given, string $stored) {
    collabUploadTo($this->member, $this->task, collabUpload(collabFixture('text'), $given))
        ->assertCreated()
        ->assertJsonPath('data.filename', $stored);

    // The name never becomes part of a storage path.
    expect(TaskAttachment::query()->sole()->storage_key)->toMatch('#^objects/[0-9a-f-]{36}/[0-9a-f-]{36}/[0-9a-f]{32}$#');
})->with([
    'unix traversal' => ['../../../etc/passwd.txt', 'passwd.txt'],
    'windows traversal' => ['..\\..\\Windows\\win.txt', 'win.txt'],
    'absolute path, hidden file' => ['/var/www/.env.txt', 'env.txt'],
    'NUL and controls' => ["evil\0name\r\n.txt", 'evilname.txt'],
    'right-to-left override' => ["invoice\u{202E}txt.exe.txt", 'invoicetxt.exe.txt'],
    'Arabic name' => ['ملاحظات الاجتماع.txt', 'ملاحظات الاجتماع.txt'],
    'trailing dots and spaces' => ['notes.txt. . ', 'notes.txt'],
]);

it('sanitises names consistently, including long and empty ones', function () {
    expect(AttachmentFilename::sanitize('..'))->toBe('file')
        ->and(AttachmentFilename::sanitize('../'))->toBe('file')
        ->and(AttachmentFilename::sanitize(''))->toBe('file')
        ->and(mb_strlen(AttachmentFilename::sanitize(str_repeat('ع', 300).'.pdf')))->toBe(255)
        ->and(AttachmentFilename::sanitize(str_repeat('ع', 300).'.pdf'))->toEndWith('.pdf')
        ->and(AttachmentFilename::sanitize("\xC3\x28bad.txt"))->toEndWith('bad.txt')
        ->and(AttachmentFilename::asciiFallback('تقرير.pdf'))->toBe('file.pdf')
        ->and(AttachmentFilename::asciiFallback('a "b"; c.pdf'))->toBe('a _b__ c.pdf');
});

// --- Idempotency and rate limits ------------------------------------------------------------------------

it('requires an Idempotency-Key and replays the same upload once (API_SPEC.md §1.4)', function () {
    $key = (string) Str::uuid();
    $this->actingAs($this->member)->post("/api/v1/tasks/{$this->task->id}/attachments", ['file' => collabUpload('a', 'a.txt')], ['Accept' => 'application/json'])
        ->assertStatus(400);

    $first = collabUploadTo($this->member, $this->task, collabUpload(collabFixture('text'), 'a.txt'), $key)->assertCreated();
    $replay = collabUploadTo($this->member, $this->task, collabUpload(collabFixture('text'), 'a.txt'), $key)->assertCreated();

    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and($replay->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and(TaskAttachment::query()->count())->toBe(1)
        ->and(collabStoredFiles())->toHaveCount(1);

    // The same key with another file is not a replay.
    collabUploadTo($this->member, $this->task, collabUpload(collabFixture('text').'changed', 'a.txt'), $key)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
    expect(TaskAttachment::query()->count())->toBe(1)->and(collabStoredFiles())->toHaveCount(1);
});

it('removes the stored file when the request rolls back after storing it', function () {
    // The quota check under the lock refuses it: the file written before the lock must go.
    $size = strlen(collabFixture('text'));
    config(['sn3.attachments.team_quota_bytes' => 5 + $size]);
    TaskAttachment::factory()->for($this->task)->withContent('12345')->create();
    $before = collabStoredFiles();

    // Another upload "commits" between the pre-check and the locked check; the file is written by then.
    $written = null;
    DB::listen(function ($query) use (&$written) {
        if ($written === null && str_contains($query->sql, 'for no key update') && str_contains($query->sql, '"teams"')) {
            $written = collabStoredFiles();
            DB::table('task_attachments')->update(['size_bytes' => 6]);
        }
    });

    collabUploadTo($this->member, $this->task, collabUpload(collabFixture('text'), 'a.txt'))->assertStatus(413);

    expect($written)->toHaveCount(2)
        ->and(collabStoredFiles())->toBe($before)
        ->and(TaskAttachment::query()->count())->toBe(1);
});

it('rate-limits uploads per user (429 with Retry-After)', function () {
    config(['sn3.rate_limits.attachment_uploads_per_minute' => 2]);

    collabUploadTo($this->member, $this->task, collabUpload(collabFixture('text'), 'a.txt'))->assertCreated();
    collabUploadTo($this->member, $this->task, collabUpload(collabFixture('pdf'), 'b.pdf'))->assertCreated();
    $limited = collabUploadTo($this->member, $this->task, collabUpload(collabFixture('text'), 'c.txt'))->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

    expect($limited->headers->get('Retry-After'))->not->toBeNull()
        ->and(TaskAttachment::query()->count())->toBe(2);

    // Another user has their own budget.
    collabUploadTo($this->other, $this->task, collabUpload(collabFixture('text'), 'd.txt'))->assertCreated();
});

// --- Who may do what ------------------------------------------------------------------------------------------

it('lets project writers upload and keeps Team Viewers out whatever they send (AUTH-04)', function (mixed $file) {
    collabUploadTo($this->viewer, $this->task, is_string($file) ? collabUpload(collabFixture($file), 'x.pdf') : $file)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect(TaskAttachment::query()->count())->toBe(0)->and(collabStoredFiles())->toBe([]);
})->with([
    'valid file' => ['pdf'],
    'disallowed file' => ['exe'],
    'no file' => [null],
]);

it('lets the uploader, the Owner and project managers delete an attachment, which removes its file', function () {
    $manager = taskTeamMember($this->team, TeamRole::Member);
    taskProjectMember($this->project, $manager, ProjectRole::Manager);
    $mine = TaskAttachment::factory()->for($this->task)->by($this->member)->create();
    $forManager = TaskAttachment::factory()->for($this->task)->by($this->member)->create();
    $forOwner = TaskAttachment::factory()->for($this->task)->by($this->member)->create();

    foreach ([$this->other, $this->admin, $this->viewer] as $user) {
        $this->actingAs($user)->deleteJson("/api/v1/attachments/{$mine->id}")->assertForbidden();
    }

    $this->actingAs($this->member)->deleteJson("/api/v1/attachments/{$mine->id}")->assertNoContent();
    $this->actingAs($manager)->deleteJson("/api/v1/attachments/{$forManager->id}")->assertNoContent();
    $this->actingAs($this->owner)->deleteJson("/api/v1/attachments/{$forOwner->id}")->assertNoContent();

    expect(TaskAttachment::query()->count())->toBe(0)
        ->and(collabStoredFiles())->toBe([])
        ->and(TaskActivity::query()->where('action', 'attachment.removed')->orderBy('id')->get()->map(fn ($e) => [$e->actor_user_id, $e->changes['moderated']])->all())
        ->toBe([[$this->member->id, false], [$manager->id, true], [$this->owner->id, true]]);

    $this->actingAs($this->member)->get("/api/v1/attachments/{$mine->id}/download")->assertNotFound();
    $this->actingAs($this->member)->deleteJson("/api/v1/attachments/{$mine->id}")->assertNotFound();
});

it('hides attachments of hidden projects, other teams, trashed tasks and guessed IDs (404; DB-T01, DB-T02)', function () {
    $private = taskProject($this->team, 'private');
    $privateTask = taskIn($private);
    $secret = TaskAttachment::factory()->for($privateTask)->withContent(collabFixture('pdf'), 'Merger.pdf', 'application/pdf')->create();
    $otherTeam = Team::factory()->create();
    $foreign = TaskAttachment::factory()->for(taskIn(taskProject($otherTeam)))->create();
    $trashedTask = taskIn($this->project);
    $ofTrashed = TaskAttachment::factory()->for($trashedTask)->create();
    $trashedTask->delete();

    foreach ([$secret->id, $foreign->id, $ofTrashed->id, (string) Str::uuid7(), 'not-a-uuid'] as $id) {
        $this->actingAs($this->admin)->get("/api/v1/attachments/{$id}/download", ['Accept' => 'application/json'])
            ->assertNotFound()->assertJsonPath('error.code', 'not_found')->assertDontSee('Merger');
        $this->actingAs($this->admin)->deleteJson("/api/v1/attachments/{$id}")->assertNotFound();
    }

    $this->actingAs($this->admin)->getJson("/api/v1/tasks/{$privateTask->id}/attachments")->assertNotFound();
    $this->actingAs($this->admin)->post("/api/v1/tasks/{$privateTask->id}/attachments", ['file' => collabUpload('a', 'a.txt')], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()])->assertNotFound();

    // The Team Owner sees private attachments without a membership row.
    $this->actingAs($this->owner)->get("/api/v1/attachments/{$secret->id}/download")->assertOk();
    expect(TaskAttachment::query()->count())->toBe(3);
});

it('re-checks access on every download: a member removed from a private project gets nothing (AUTH-05, DB-T08)', function () {
    $private = taskProject($this->team, 'private');
    taskProjectMember($private, $this->member, ProjectRole::Contributor);
    $attachment = TaskAttachment::factory()->for(taskIn($private))->withContent(collabFixture('pdf'), 'plan.pdf', 'application/pdf')->create();
    $url = "/api/v1/attachments/{$attachment->id}/download";

    $this->actingAs($this->member)->get($url)->assertOk();

    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$private->id}/members/{$this->member->id}")->assertNoContent();

    $this->actingAs($this->member)->get($url, ['Accept' => 'application/json'])->assertNotFound();

    // The same after a team removal, and for a project made private.
    $open = TaskAttachment::factory()->for($this->task)->create();
    $this->actingAs($this->other)->get("/api/v1/attachments/{$open->id}/download")->assertOk();
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}", ['visibility' => 'private'], ['If-Match' => '"1"'])->assertOk();
    $this->actingAs($this->other)->get("/api/v1/attachments/{$open->id}/download", ['Accept' => 'application/json'])->assertNotFound();
});

it('refuses attachment writes in archived projects and teams (423), and keeps downloads working', function (string $archived) {
    $attachment = TaskAttachment::factory()->for($this->task)->by($this->member)->create();
    ($archived === 'team' ? $this->team : $this->project)->forceFill(['archived_at' => now()])->save();

    collabUploadTo($this->member, $this->task, collabUpload(collabFixture('text'), 'a.txt'))->assertStatus(423);
    $this->actingAs($this->member)->deleteJson("/api/v1/attachments/{$attachment->id}")->assertStatus(423);
    $this->actingAs($this->viewer)->get("/api/v1/attachments/{$attachment->id}/download")->assertOk();

    expect(TaskAttachment::query()->count())->toBe(1)->and(collabStoredFiles())->toHaveCount(1);
})->with(['project', 'team']);

it('answers 410 when a visible attachment\'s file is missing, without details', function () {
    $attachment = TaskAttachment::factory()->for($this->task)->create();
    Storage::disk('attachments')->delete($attachment->storage_key);

    $this->actingAs($this->member)->get("/api/v1/attachments/{$attachment->id}/download", ['Accept' => 'application/json'])
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'expired')
        ->assertDontSee('objects/');
});

it('requires authentication for every attachment endpoint', function () {
    $attachment = TaskAttachment::factory()->for($this->task)->create();

    $this->getJson("/api/v1/tasks/{$this->task->id}/attachments")->assertUnauthorized();
    $this->post("/api/v1/tasks/{$this->task->id}/attachments", ['file' => collabUpload('a', 'a.txt')], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()])->assertUnauthorized();
    $this->get("/api/v1/attachments/{$attachment->id}/download", ['Accept' => 'application/json'])->assertUnauthorized();
    $this->deleteJson("/api/v1/attachments/{$attachment->id}")->assertUnauthorized();
});
