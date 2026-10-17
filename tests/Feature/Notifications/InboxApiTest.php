<?php

require_once __DIR__.'/NotificationHelpers.php';

use App\Models\InboxNotification;
use App\Models\Task;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

// CORE-009: the inbox endpoints (API_SPEC.md §9: GET /notifications, POST /notifications/{id}/read,
// POST /notifications/read-all, GET /notifications/unread-count; §1.2 envelopes, §1.4 cursors;
// ADR-029 §3–§4). Only the recipient's own notifications; anyone else's is a 404 like a random ID.

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->alice = taskTeamMember($this->team);
    $this->bob = taskTeamMember($this->team);
    $this->project = taskProject($this->team, attributes: ['project_key' => 'WEB']);
});

/**
 * Assigns Bob `$count` tasks, one notification each, a second apart.
 *
 * @return list<Task>
 */
function inboxAssignMany(int $count): array
{
    $tasks = [];

    for ($i = 1; $i <= $count; $i++) {
        $task = taskIn(test()->project, ['title' => "Task {$i}"]);
        notifyAssign(test()->alice, $task, test()->bob);
        test()->travel(1)->seconds();
        $tasks[] = $task;
    }

    return $tasks;
}

it('lists the own inbox newest event first, cursor-paginated, with the standard envelope', function () {
    $tasks = inboxAssignMany(3);

    $first = inboxOf($this->bob, ['limit' => 2]);

    expect(collect($first->json('data'))->pluck('task.id')->all())->toBe([$tasks[2]->id, $tasks[1]->id])
        ->and($first->json('meta.has_more'))->toBeTrue()
        ->and($first->json('meta.limit'))->toBe(2)
        ->and($first->json('meta.request_id'))->toBeString()
        ->and(array_keys($first->json('data.0')))->toBe(['id', 'type', 'team', 'project', 'task', 'comment_id', 'actor', 'fields', 'event_count', 'invitation', 'read_at', 'created_at', 'updated_at']);

    $second = inboxOf($this->bob, ['limit' => 2, 'cursor' => $first->json('meta.next_cursor')]);

    expect(collect($second->json('data'))->pluck('task.id')->all())->toBe([$tasks[0]->id])
        ->and($second->json('meta.has_more'))->toBeFalse()
        ->and($second->json('meta.next_cursor'))->toBeNull();

    // A later event on the oldest one moves it to the top.
    notifyWatch($this->bob, $tasks[0])->assertCreated();
    notifyEditTask($this->alice, $tasks[0], ['priority' => 'high'])->assertOk();

    expect(inboxOf($this->bob)->json('data.0'))->toMatchArray(['type' => 'task.updated'])
        ->and(inboxOf($this->bob)->json('data.0.task.id'))->toBe($tasks[0]->id);
});

it('filters unread notifications and binds the cursor to the filter', function () {
    inboxAssignMany(3);
    $oldest = storedNotificationsOf($this->bob)->first();
    $this->actingAs($this->bob)->postJson("/api/v1/notifications/{$oldest->id}/read")->assertOk();

    $unread = inboxOf($this->bob, ['status' => 'unread', 'limit' => 1]);

    expect($unread->json('data'))->toHaveCount(1)
        ->and(inboxOf($this->bob, ['status' => 'unread'])->json('data'))->toHaveCount(2)
        ->and(inboxOf($this->bob, ['status' => 'all'])->json('data'))->toHaveCount(3);

    $this->actingAs($this->bob)->getJson('/api/v1/notifications?status=all&cursor='.$unread->json('meta.next_cursor'))->assertStatus(400);
    $this->actingAs($this->bob)->getJson('/api/v1/notifications?status=archived')->assertStatus(422)->assertJsonPath('error.fields.status.0', 'in');
    $this->actingAs($this->bob)->getJson('/api/v1/notifications?limit=101')->assertStatus(422);
});

it('marks one notification read, idempotently, and returns it', function () {
    inboxAssignMany(2);
    $notification = storedNotificationsOf($this->bob)->first();

    $read = $this->actingAs($this->bob)->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();

    expect($read->json('data.id'))->toBe($notification->id)
        ->and($read->json('data.read_at'))->toBeString()
        ->and(unreadCountOf($this->bob))->toBe(1);

    $this->travel(1)->minutes();
    $again = $this->actingAs($this->bob)->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();

    expect($again->json('data.read_at'))->toBe($read->json('data.read_at'));
});

it('marks the whole visible inbox read and answers how many changed', function () {
    inboxAssignMany(3);

    $this->actingAs($this->bob)->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.marked_read', 3);
    $this->actingAs($this->bob)->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.marked_read', 0);

    expect(unreadCountOf($this->bob))->toBe(0)
        ->and(InboxNotification::query()->whereNull('read_at')->count())->toBe(0);
});

it('never lets anyone read or mark another user\'s notifications', function () {
    inboxAssignMany(1);
    $bobs = storedNotificationsOf($this->bob)->first();
    $stranger = Team::factory()->create()->owner;

    foreach ([$this->alice, $this->owner, $stranger] as $user) {
        $this->actingAs($user)->postJson("/api/v1/notifications/{$bobs->id}/read")->assertNotFound()->assertJsonPath('error.code', 'not_found');
        expect(inboxOf($user)->json('data'))->toBe([])->and(unreadCountOf($user))->toBe(0);
        $this->actingAs($user)->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.marked_read', 0);
    }

    expect($bobs->fresh()->read_at)->toBeNull();
});

it('answers 404 to random and malformed IDs and rejects a body', function () {
    inboxAssignMany(1);
    $notification = storedNotificationsOf($this->bob)->first();

    foreach ([(string) Str::uuid7(), 'nope', '1'] as $id) {
        $this->actingAs($this->bob)->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
    }

    $this->actingAs($this->bob)->postJson("/api/v1/notifications/{$notification->id}/read", ['read' => false])
        ->assertStatus(422)->assertJsonPath('error.fields.read.0', 'unknown_field');
    $this->actingAs($this->bob)->postJson('/api/v1/notifications/read-all', ['before' => 'x'])
        ->assertStatus(422)->assertJsonPath('error.fields.before.0', 'unknown_field');

    expect(unreadCountOf($this->bob))->toBe(1);
});

it('requires a session for every inbox endpoint', function () {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
    $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
    $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
    $this->postJson('/api/v1/notifications/'.Str::uuid7().'/read')->assertUnauthorized();
    $this->get('/inbox')->assertRedirect('/login');
});

it('enforces CSRF on the inbox writes', function () {
    inboxAssignMany(1);
    enforceCsrf();

    $this->actingAs($this->bob)->postJson('/api/v1/notifications/read-all')->assertStatus(419);
});

it('renders a deleted actor as null', function () {
    $actor = taskTeamMember($this->team);
    $task = taskIn($this->project);
    notifyAssign($actor, $task, $this->bob);

    DB::table('notifications')->update(['actor_user_id' => null]);

    expect(inboxOf($this->bob)->json('data.0.actor'))->toBeNull();
});

it('caps the unread count at 100, in the API and on every page, while the list keeps everything', function () {
    // 50 tasks with three unread task notifications each: 150 unread, straight in the table.
    $rows = [];

    foreach (range(1, 50) as $i) {
        $task = taskIn($this->project);

        foreach (['task.assigned', 'task.updated', 'task.commented'] as $type) {
            $rows[] = [
                'id' => (string) Str::uuid7(), 'recipient_user_id' => $this->bob->id, 'team_id' => $this->team->id,
                'project_id' => $this->project->id, 'task_id' => $task->id, 'actor_user_id' => $this->alice->id,
                'type' => $type, 'payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ];
        }
    }

    DB::table('notifications')->insert($rows);

    expect(unreadCountOf($this->bob))->toBe(100)
        ->and(inboxOf($this->bob, ['status' => 'unread', 'limit' => 100])->json('meta.has_more'))->toBeTrue();
    $this->actingAs($this->bob)->get('/inbox')->assertInertia(fn (Assert $page) => $page->where('inbox.unread_count', 100));

    // Below the cap the count is exact.
    DB::table('notifications')->whereIn('id', array_column(array_slice($rows, 0, 51), 'id'))->update(['read_at' => now()]);
    expect(unreadCountOf($this->bob))->toBe(99);
    $this->actingAs($this->bob)->get('/inbox')->assertInertia(fn (Assert $page) => $page->where('inbox.unread_count', 99));
});
