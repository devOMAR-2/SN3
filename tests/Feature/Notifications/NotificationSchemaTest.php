<?php

require_once __DIR__.'/NotificationHelpers.php';

use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// CORE-009: the `notifications` and `task_watchers` tables (DATABASE.md §8, §12; ADR-022 §5 keys;
// ADR-029 §7). The database enforces each notification's shape, the collapse rule and the cascades
// that take notifications and watches away with their team, project, task or comment. The trash
// purge's recursive key guard (TrashPurgeTest) covers both tables too.

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->bob = taskTeamMember($this->team);
    $this->project = taskProject($this->team);
    $this->task = taskIn($this->project);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function insertNotificationRow(array $overrides): void
{
    $overrides = array_map(fn (mixed $value): mixed => $value instanceof Closure ? $value() : $value, $overrides);

    DB::table('notifications')->insert([
        'id' => (string) Str::uuid7(),
        'recipient_user_id' => test()->bob->id,
        'team_id' => test()->team->id,
        'project_id' => test()->project->id,
        'task_id' => test()->task->id,
        'type' => 'task.updated',
        'payload' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

it('refuses notifications of an unknown type, the wrong shape or an unbounded payload', function (array $row) {
    expect(fn () => insertNotificationRow($row))->toThrow(QueryException::class);
})->with([
    'unknown type' => [['type' => 'task.deleted']],
    'task type without a task' => [['task_id' => null]],
    'mention without a comment' => [['type' => 'comment.mentioned']],
    'invitation with a project' => [['type' => 'team.invitation']],
    'payload not an object' => [['payload' => '[]']],
    'payload too large' => [['payload' => json_encode(['fields' => array_fill(0, 200, 'description')])]],
    'no events' => [['event_count' => 0]],
    'updated before created' => [['created_at' => now(), 'updated_at' => now()->subMinute()]],
    'task of another project' => [['project_id' => fn () => taskProject(test()->team)->id]],
]);

it('keeps one unread notification per recipient, task and collapsing type, read ones aside', function () {
    insertNotificationRow([]);
    insertNotificationRow(['type' => 'task.commented']);
    insertNotificationRow(['read_at' => now()]);
    insertNotificationRow(['read_at' => now()]);

    expect(fn () => insertNotificationRow([]))->toThrow(QueryException::class);
});

it('removes notifications and watches with their task, project and team (ADR-022 §5)', function () {
    notifyWatch($this->bob, $this->task)->assertCreated();
    insertNotificationRow([]);

    DB::transaction(function (): void {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::table('projects')->where('id', $this->project->id)->delete();
    });

    expect(DB::table('notifications')->count())->toBe(0)->and(DB::table('task_watchers')->count())->toBe(0);
});

it('removes a mention notification with its comment when the comment is pruned', function () {
    $comment = notifyComment($this->owner, $this->task, "@{$this->bob->id}");

    expect(storedNotificationsOf($this->bob))->toHaveCount(1);

    DB::table('task_comments')->where('id', $comment->json('data.id'))->delete();

    expect(storedNotificationsOf($this->bob))->toHaveCount(0);
});
