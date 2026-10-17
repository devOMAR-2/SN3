<?php

require_once __DIR__.'/CollaborationHelpers.php';

use App\Domain\Attachments\AttachmentStorage;
use App\Domain\Teams\TeamRole;
use App\Models\TaskAttachment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// CORE-008: concurrent uploads and the team quota (API_SPEC.md §15 "storage quota race conditions",
// AGENTS.md §8 "quota exhaustion"). Real PHP processes race on committed data behind an advisory-lock
// barrier, as in DB-T06 (OwnershipTransferConcurrencyTest), so this file does not use RefreshDatabase
// and cleans up after itself. Both uploads pass the quota pre-check; the team lock lets exactly as
// many commit as the quota holds, and the loser's stored file is removed.

const UPLOAD_BARRIER_KEY = 640_008;

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);

    $this->team = Team::factory()->create();
    $this->uploaders = collect(range(1, 2))->map(fn () => taskTeamMember($this->team, TeamRole::Member));
    $this->task = taskIn(taskProject($this->team));
    $this->files = collect(range(1, 2))->map(function (int $i): string {
        $path = sys_get_temp_dir()."/sn3-race-{$i}-".Str::random(8).'.txt';
        file_put_contents($path, str_repeat("line {$i}\n", 10));

        return $path;
    });
});

afterEach(function () {
    $userIds = [$this->team->owner_user_id, ...$this->uploaders->pluck('id')->all()];

    DB::transaction(function (): void {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::table('teams')->where('id', $this->team->id)->delete();
    });
    User::query()->whereKey($userIds)->delete();
    Storage::disk('attachments')->deleteDirectory(app(AttachmentStorage::class)->teamPrefix($this->team->id));
    File::delete($this->files->all());
});

function uploadInProcess(string $taskId, string $userId, string $path): string
{
    $code = sprintf(
        'DB::select("select pg_advisory_lock_shared(%d)"); try { app(App\Domain\Attachments\Actions\UploadAttachment::class)->handle(App\Models\Task::findOrFail("%s"), App\Models\User::findOrFail("%s"), "%s", "notes.txt", "text/plain"); echo "stored"; } catch (Throwable $e) { echo class_basename($e).($e instanceof Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? ":".$e->getStatusCode() : ""); }',
        UPLOAD_BARRIER_KEY,
        $taskId,
        $userId,
        $path,
    );

    return 'php artisan tinker --execute='.escapeshellarg($code).' --no-interaction';
}

/**
 * @return list<string> the sorted outcomes
 */
function raceUploads(object $test, int $quota): array
{
    $connection = config('database.connections.pgsql');
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => (string) $connection['database'],
        'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) $connection['password'],
        'DB_URL' => '',
        'SN3_ATTACHMENTS_DISK' => 'attachments',
        'SN3_ATTACHMENTS_TEAM_QUOTA_BYTES' => (string) $quota,
    ];

    DB::select('select pg_advisory_lock(?)', [UPLOAD_BARRIER_KEY]);

    try {
        $pool = Process::pool(fn ($pool) => $test->uploaders->map(fn (User $user, int $i) => $pool->path(base_path())->env($env)->timeout(60)
            ->command(uploadInProcess($test->task->id, $user->id, $test->files[$i])))->all())->start();

        $deadline = microtime(true) + 30;
        while (DB::table('pg_locks')->where('locktype', 'advisory')->where('objid', UPLOAD_BARRIER_KEY)->where('objsubid', 1)->whereRaw('database = (select oid from pg_database where datname = current_database())')->where('granted', false)->count() < 2) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Both upload processes did not reach the barrier within 30 seconds.');
            }
            usleep(20_000);
        }
    } finally {
        DB::select('select pg_advisory_unlock(?)', [UPLOAD_BARRIER_KEY]);
    }

    return $pool->wait()->collect()->map(fn ($result) => trim($result->output()))->sort()->values()->all();
}

it('lets only one of two racing uploads in when the quota holds one, and leaves no orphan file', function () {
    $size = filesize($this->files[0]);

    expect(raceUploads($this, quota: $size + 10))->toBe(['HttpException:413', 'stored']);

    $rows = TaskAttachment::query()->where('team_id', $this->team->id)->get();
    expect($rows)->toHaveCount(1)
        ->and(Storage::disk('attachments')->allFiles(app(AttachmentStorage::class)->teamPrefix($this->team->id)))->toBe([$rows[0]->storage_key]);
});

it('stores both racing uploads when the quota holds both', function () {
    $size = filesize($this->files[0]);

    expect(raceUploads($this, quota: 2 * $size + 10))->toBe(['stored', 'stored']);

    expect(TaskAttachment::query()->where('team_id', $this->team->id)->count())->toBe(2)
        ->and(Storage::disk('attachments')->allFiles(app(AttachmentStorage::class)->teamPrefix($this->team->id)))->toHaveCount(2);
});
