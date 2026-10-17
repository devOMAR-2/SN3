<?php

use App\Domain\Invitations\Actions\CreateInvitation;
use App\Domain\Invitations\InvitationType;
use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;

// DATABASE.md §11 "Invite acceptance" and §16 DB-T07: concurrent redemptions never exceed an
// invitation's uses, and a member's duplicate accept is idempotent. Separate PHP processes race on
// committed data (no RefreshDatabase; the test cleans up after itself). Each child waits on a shared
// advisory lock the test holds, released once both are queued on it (see
// OwnershipTransferConcurrencyTest).

const INVITATION_BARRIER_KEY = 640_025;

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    Notification::fake();

    $this->team = Team::factory()->create();
    $this->users = User::factory()->count(2)->create();
});

afterEach(function () {
    $userIds = [$this->team->owner_user_id, ...$this->users->pluck('id')->all()];

    DB::table('team_invitations')->where('team_id', $this->team->id)->delete();
    Team::withTrashed()->whereKey($this->team->id)->forceDelete();
    User::query()->whereKey($userIds)->delete();
});

function acceptInProcess(string $token, User $user): string
{
    $code = sprintf(
        'DB::select("select pg_advisory_lock_shared(%d)"); try { $r = app(App\Domain\Invitations\Actions\AcceptInvitation::class)->handle("%s", App\Models\User::findOrFail("%s")); echo $r["created"] ? "joined" : "member"; } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { echo "http-".$e->getStatusCode(); } catch (Throwable $e) { echo class_basename($e); }',
        INVITATION_BARRIER_KEY,
        $token,
        $user->id,
    );

    return 'php artisan tinker --execute='.escapeshellarg($code).' --no-interaction';
}

/**
 * @param  list<string>  $commands
 * @return list<string> sorted outputs
 */
function raceAccepts(array $commands): array
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
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
    ];

    DB::select('select pg_advisory_lock(?)', [INVITATION_BARRIER_KEY]);

    try {
        $pool = Process::pool(function ($pool) use ($commands, $env): void {
            foreach ($commands as $command) {
                $pool->path(base_path())->env($env)->timeout(60)->command($command);
            }
        })->start();

        $deadline = microtime(true) + 30;
        while (DB::table('pg_locks')->where('locktype', 'advisory')->where('objid', INVITATION_BARRIER_KEY)->where('objsubid', 1)->whereRaw('database = (select oid from pg_database where datname = current_database())')->where('granted', false)->count() < count($commands)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The accept processes did not reach the barrier within 30 seconds.');
            }
            usleep(20_000);
        }
    } finally {
        DB::select('select pg_advisory_unlock(?)', [INVITATION_BARRIER_KEY]);
    }

    return $pool->wait()->collect()->map(fn ($result) => trim($result->output()))->sort()->values()->all();
}

it('gives the last use of a share link to exactly one of two concurrent users', function () {
    ['invitation' => $invitation, 'token' => $token] = app(CreateInvitation::class)
        ->handle($this->team, $this->team->owner, InvitationType::Link, TeamRole::Member, null);
    DB::table('team_invitations')->where('id', $invitation->id)->update(['use_count' => 19]);

    [$first, $second] = $this->users->all();

    expect(raceAccepts([acceptInProcess($token, $first), acceptInProcess($token, $second)]))->toBe(['http-410', 'joined']);

    $row = TeamInvitation::query()->select(TeamInvitation::COLUMNS)->findOrFail($invitation->id);

    expect($row->use_count)->toBe(20)
        ->and($row->accepted_at)->not->toBeNull()
        ->and(TeamMembership::query()->where('team_id', $this->team->id)->whereIn('user_id', $this->users->pluck('id'))->count())->toBe(1);
});

it('joins once when the invitee accepts twice at the same time', function () {
    $invitee = $this->users->first();
    ['invitation' => $invitation, 'token' => $token] = app(CreateInvitation::class)
        ->handle($this->team, $this->team->owner, InvitationType::Email, TeamRole::Viewer, $invitee->email);

    expect(raceAccepts([acceptInProcess($token, $invitee), acceptInProcess($token, $invitee)]))->toBe(['joined', 'member']);

    expect(TeamInvitation::query()->select(TeamInvitation::COLUMNS)->findOrFail($invitation->id)->use_count)->toBe(1)
        ->and(TeamMembership::query()->where('team_id', $this->team->id)->where('user_id', $invitee->id)->value('role'))->toBe(TeamRole::Viewer);
});
