<?php

require_once __DIR__.'/NotificationHelpers.php';

use App\Domain\Invitations\Actions\CreateInvitation;
use App\Domain\Invitations\InvitationType;
use App\Domain\Teams\TeamRole;
use App\Models\InboxNotification;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// CORE-009: what causes a notification, and for whom (API_SPEC.md §9 "Notifications triggered by
// assignment, comment mention, invitation, and task update for watchers; batch/dedupe noisy events";
// FR-COL-003, FR-COL-004; ADR-029 §2). Never the actor; the task types collapse while unread.
// TEST-FR-COL-003, TEST-FR-COL-004.

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->alice = taskTeamMember($this->team);
    $this->bob = taskTeamMember($this->team);
    $this->viewer = taskTeamMember($this->team, TeamRole::Viewer);
    $this->project = taskProject($this->team, attributes: ['project_key' => 'WEB', 'name' => 'Website']);
    $this->task = taskIn($this->project, ['title' => 'Fix login']);
});

it('notifies a user someone else assigns, on edit and on create, with live names and no copied text', function () {
    notifyAssign($this->alice, $this->task, $this->bob);

    $created = $this->actingAs($this->alice)->postJson("/api/v1/projects/{$this->project->id}/tasks", [
        'title' => 'Second task',
        'assignee_user_id' => $this->bob->id,
    ], taskIdempotencyHeader())->assertCreated();

    $inbox = inboxOf($this->bob)->json('data');

    expect($inbox)->toHaveCount(2)
        ->and($inbox[0]['type'])->toBe('task.assigned')
        ->and($inbox[0]['task'])->toBe(['id' => $created->json('data.id'), 'key' => 'WEB-2', 'title' => 'Second task'])
        ->and($inbox[1]['task']['key'])->toBe('WEB-1')
        ->and($inbox[1]['actor'])->toBe(['id' => $this->alice->id, 'name' => $this->alice->name])
        ->and($inbox[1]['project'])->toBe(['id' => $this->project->id, 'key' => 'WEB', 'name' => 'Website'])
        ->and($inbox[1]['team'])->toBe(['id' => $this->team->id, 'name' => $this->team->name])
        ->and($inbox[1]['read_at'])->toBeNull();

    // The row keeps IDs and field names only (DATABASE.md §8).
    $payloads = DB::table('notifications')->pluck('payload')->all();
    foreach ($payloads as $payload) {
        expect((string) $payload)->not->toContain('Fix login')->not->toContain('Website');
    }
});

it('does not notify users who assign themselves, or anyone on an unassignment', function () {
    notifyAssign($this->alice, $this->task, $this->alice);
    notifyAssign($this->alice, $this->task, null);

    expect(storedNotificationsOf($this->alice))->toBeEmpty()->and(InboxNotification::query()->count())->toBe(0);
});

it('notifies watchers of real edits with the changed field names, never the editor', function () {
    notifyWatch($this->alice, $this->task)->assertCreated();
    notifyWatch($this->bob, $this->task)->assertCreated();

    notifyEditTask($this->alice, $this->task, ['priority' => 'high', 'title' => 'Fix login now'])->assertOk();

    expect(storedNotificationsOf($this->alice))->toBeEmpty();

    $item = inboxOf($this->bob)->json('data.0');
    expect($item['type'])->toBe('task.updated')
        ->and($item['fields'])->toBe(['priority', 'title'])
        ->and($item['task']['title'])->toBe('Fix login now')
        ->and($item['event_count'])->toBe(1);

    // A no-op edit notifies nobody.
    notifyEditTask($this->alice, $this->task, ['priority' => 'high'])->assertOk();
    expect(inboxOf($this->bob)->json('data.0.event_count'))->toBe(1);
});

it('notifies watchers when a board move changes the status, not when it only reorders (CORE-007)', function () {
    notifyWatch($this->bob, $this->task)->assertCreated();
    $other = taskIn($this->project, ['title' => 'Other']);
    $todo = $this->task->status_id;
    $done = taskStatusId($this->project, 'done');

    // Reorder within the column: no notification.
    $this->actingAs($this->alice)->postJson("/api/v1/tasks/{$this->task->id}/move", ['status_id' => $todo, 'after_task_id' => $other->id], taskIfMatch($this->task))->assertOk();
    expect(storedNotificationsOf($this->bob))->toBeEmpty();

    $this->task->refresh();
    $this->actingAs($this->alice)->postJson("/api/v1/tasks/{$this->task->id}/move", ['status_id' => $done], taskIfMatch($this->task))->assertOk();

    $item = inboxOf($this->bob)->json('data.0');
    expect($item['type'])->toBe('task.updated')
        ->and($item['fields'])->toBe(['status_id'])
        ->and($item['actor']['id'])->toBe($this->alice->id)
        ->and(storedNotificationsOf($this->alice))->toBeEmpty();
});

it('collapses noisy edits into one unread notification and starts a new one once it is read', function () {
    notifyWatch($this->bob, $this->task)->assertCreated();

    notifyEditTask($this->alice, $this->task, ['priority' => 'high'])->assertOk();
    notifyEditTask($this->owner, $this->task, ['due_date' => '2026-12-01'])->assertOk();
    notifyEditTask($this->alice, $this->task, ['priority' => 'low'])->assertOk();

    $inbox = inboxOf($this->bob)->json('data');
    expect($inbox)->toHaveCount(1)
        ->and($inbox[0]['event_count'])->toBe(3)
        ->and($inbox[0]['fields'])->toBe(['due_date', 'priority'])
        ->and($inbox[0]['actor']['id'])->toBe($this->alice->id)
        ->and(unreadCountOf($this->bob))->toBe(1);

    $this->actingAs($this->bob)->postJson("/api/v1/notifications/{$inbox[0]['id']}/read")->assertOk();
    notifyEditTask($this->alice, $this->task, ['title' => 'Renamed'])->assertOk();

    $inbox = inboxOf($this->bob)->json('data');
    expect($inbox)->toHaveCount(2)
        ->and($inbox[0]['fields'])->toBe(['title'])
        ->and($inbox[0]['event_count'])->toBe(1)
        ->and($inbox[1]['read_at'])->not->toBeNull()
        ->and(unreadCountOf($this->bob))->toBe(1);
});

it('gives a watcher who is newly assigned the assignment only, and other watchers the update', function () {
    notifyWatch($this->bob, $this->task)->assertCreated();
    notifyWatch($this->viewer, $this->task)->assertCreated();

    notifyAssign($this->alice, $this->task, $this->bob);

    expect(inboxTypes($this->bob))->toBe(['task.assigned'])
        ->and(inboxTypes($this->viewer))->toBe(['task.updated'])
        ->and(inboxOf($this->viewer)->json('data.0.fields'))->toBe(['assignee_user_id']);
});

it('notifies watchers of new comments, except the author and those the comment mentions', function () {
    foreach ([$this->alice, $this->bob, $this->viewer] as $watcher) {
        notifyWatch($watcher, $this->task)->assertCreated();
    }

    notifyComment($this->alice, $this->task, "Over to you @{$this->bob->id}");
    notifyComment($this->owner, $this->task, 'Second');

    expect(inboxTypes($this->bob))->toBe(['task.commented', 'comment.mentioned'])
        ->and(inboxOf($this->bob)->json('data.0.event_count'))->toBe(1)
        ->and(inboxOf($this->viewer)->json('data.0'))->toMatchArray(['type' => 'task.commented', 'event_count' => 2])
        ->and(inboxOf($this->alice)->json('data.0'))->toMatchArray(['type' => 'task.commented', 'event_count' => 1])
        ->and(inboxOf($this->alice)->json('data.0.actor.id'))->toBe($this->owner->id);

    // Comment edits are not new comments.
    $comment = $this->task->comments()->where('author_user_id', $this->alice->id)->firstOrFail();
    $this->actingAs($this->alice)->patchJson("/api/v1/tasks/{$this->task->id}/comments/{$comment->id}", ['body' => 'Edited'])->assertOk();
    expect(inboxOf($this->viewer)->json('data.0.event_count'))->toBe(2);
});

it('notifies each newly mentioned user once per comment, never the author, with the comment', function () {
    $response = notifyComment($this->alice, $this->task, "@{$this->bob->id} and @{$this->alice->id} and @{$this->viewer->id}");
    $commentId = $response->json('data.id');

    expect(inboxOf($this->bob)->json('data'))->toHaveCount(1)
        ->and(inboxOf($this->bob)->json('data.0'))->toMatchArray(['type' => 'comment.mentioned', 'comment_id' => $commentId])
        ->and(inboxTypes($this->viewer))->toBe(['comment.mentioned'])
        ->and(storedNotificationsOf($this->alice))->toBeEmpty();

    // Removing and re-adding the mention does not notify twice.
    $this->actingAs($this->alice)->patchJson("/api/v1/tasks/{$this->task->id}/comments/{$commentId}", ['body' => 'nobody'])->assertOk();
    $this->actingAs($this->alice)->patchJson("/api/v1/tasks/{$this->task->id}/comments/{$commentId}", ['body' => "@{$this->bob->id} again"])->assertOk();

    expect(storedNotificationsOf($this->bob))->toHaveCount(1)->and(inboxTypes($this->bob))->toBe(['comment.mentioned']);
});

it('adds the in-app invitation notification for a verified account at the invited address only', function () {
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $unverified = User::factory()->unverified()->create(['email' => 'unverified@example.com']);

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/invitations", ['type' => 'email', 'email' => 'Invitee@Example.com', 'role' => 'member'], taskIdempotencyHeader())->assertCreated();
    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/invitations", ['type' => 'email', 'email' => 'unverified@example.com', 'role' => 'viewer'], taskIdempotencyHeader())->assertCreated();
    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/invitations", ['type' => 'link', 'role' => 'member'], taskIdempotencyHeader())->assertCreated();

    $item = inboxOf($invitee)->json('data.0');

    expect(inboxOf($invitee)->json('data'))->toHaveCount(1)
        ->and($item['type'])->toBe('team.invitation')
        ->and($item['team'])->toBe(['id' => $this->team->id, 'name' => $this->team->name])
        ->and($item['actor']['id'])->toBe($this->owner->id)
        ->and($item['invitation']['role'])->toBe('member')
        ->and($item['project'])->toBeNull()
        ->and($item['task'])->toBeNull()
        ->and(json_encode($item))->not->toContain('token')
        ->and(storedNotificationsOf($unverified))->toBeEmpty()
        ->and(InboxNotification::query()->count())->toBe(1);
});

it('hides an invitation notification once the invitation is accepted, revoked or expired', function (string $state) {
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    ['invitation' => $invitation, 'token' => $token] = app(CreateInvitation::class)->handle($this->team, $this->owner, InvitationType::Email, TeamRole::Member, 'invitee@example.com');

    expect(unreadCountOf($invitee))->toBe(1);

    match ($state) {
        'accepted' => $this->actingAs($invitee)->postJson("/api/v1/invitations/{$token}/accept")->assertCreated(),
        'revoked' => $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}/invitations/{$invitation->id}")->assertNoContent(),
        'expired' => $this->travel(TeamInvitation::TTL_DAYS)->days(),
    };

    expect(inboxOf($invitee)->json('data'))->toBe([])->and(unreadCountOf($invitee))->toBe(0);
})->with(['accepted', 'revoked', 'expired']);

it('records nothing for users who turned both email and in-app notifications off', function () {
    $this->actingAs($this->bob)->patchJson('/api/v1/me', ['email_notifications' => false, 'in_app_notifications' => false])->assertOk();

    notifyAssign($this->alice, $this->task, $this->bob);
    notifyComment($this->alice, $this->task, "@{$this->bob->id}");

    expect(storedNotificationsOf($this->bob))->toBeEmpty();
});

it('keeps the inbox empty for users who turned in-app notifications off, and shows it again when back on', function () {
    $this->actingAs($this->bob)->patchJson('/api/v1/me', ['in_app_notifications' => false])
        ->assertOk()
        ->assertJsonPath('data.in_app_notifications', false)
        ->assertJsonPath('data.email_notifications', true);

    notifyAssign($this->alice, $this->task, $this->bob);

    expect(inboxOf($this->bob)->json('data'))->toBe([])
        ->and(unreadCountOf($this->bob))->toBe(0)
        ->and(storedNotificationsOf($this->bob))->toHaveCount(1);

    $this->actingAs($this->bob)->patchJson('/api/v1/me', ['in_app_notifications' => true])->assertOk();

    expect(inboxTypes($this->bob))->toBe(['task.assigned']);
});

it('validates the notification preferences as strict booleans', function (string $field, mixed $value, string $code) {
    $this->actingAs($this->bob)->patchJson('/api/v1/me', [$field => $value])
        ->assertStatus(422)
        ->assertJsonPath("error.fields.{$field}.0", $code);
})->with([
    'string' => ['email_notifications', 'yes', 'boolean'],
    'number' => ['in_app_notifications', 1, 'boolean'],
    'null' => ['email_notifications', null, 'required'],
]);
