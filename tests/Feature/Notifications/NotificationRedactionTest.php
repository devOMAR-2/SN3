<?php

require_once __DIR__.'/NotificationHelpers.php';

use App\Domain\Comments\Events\CommentAdded;
use App\Domain\Notifications\Notifier;
use App\Domain\Projects\ProjectRole;
use App\Domain\Tasks\Events\TaskChanged;
use App\Domain\Teams\TeamRole;
use App\Models\InboxNotification;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

// NOT-01 (FR-COL-005, FR-TEAM-006, FR-PROJ-007, DATABASE.md §8 and §16 DB-T08, API_SPEC.md §9 and §12;
// ADR-029 §3): after a member loses access to a project, their notifications about it are left out of
// the inbox, the unread count, "mark read" and "mark all read", and no answer names the project. Every
// way access ends is covered: team removal and leaving, removal from a private project, the project
// made private, a trashed project, task or team, and an ownership transfer; a demotion keeps exactly
// what the member can still see. The check is at read time, so the same rows come back with access.
// TEST-FR-COL-005.

uses(RefreshDatabase::class);

const SECRET_NAME = 'Secret Plans';
const SECRET_TITLE = 'Classified launch';

beforeEach(function () {
    Notification::fake();
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->alice = taskTeamMember($this->team);
    $this->bob = taskTeamMember($this->team);
    $this->admin = taskTeamMember($this->team, TeamRole::Admin);

    $this->secret = taskProject($this->team, 'private', ['project_key' => 'SEC', 'name' => SECRET_NAME]);
    taskProjectMember($this->secret, $this->alice, ProjectRole::Manager);
    taskProjectMember($this->secret, $this->bob);
    $this->secretTask = taskIn($this->secret, ['title' => SECRET_TITLE]);

    $this->open = taskProject($this->team, attributes: ['project_key' => 'WEB', 'name' => 'Website']);
    $this->openTask = taskIn($this->open, ['title' => 'Public work']);

    // Bob has three unread notifications about the private project and one about the open one.
    notifyWatch($this->bob, $this->secretTask)->assertCreated();
    notifyAssign($this->alice, $this->secretTask, $this->bob);
    notifyComment($this->alice, $this->secretTask, "Please look @{$this->bob->id}");
    notifyEditTask($this->alice, $this->secretTask, ['priority' => 'high'])->assertOk();
    notifyAssign($this->alice, $this->openTask, $this->bob);

    $this->secretIds = storedNotificationsOf($this->bob)->where('project_id', $this->secret->id)->pluck('id')->all();
    expect($this->secretIds)->toHaveCount(3)->and(unreadCountOf($this->bob))->toBe(4);
});

/**
 * Bob's inbox shows nothing of the private project, by any read path, and nothing names it.
 */
function expectSecretHidden(User $bob, array $secretIds, int $remaining): void
{
    $list = test()->actingAs($bob)->getJson('/api/v1/notifications')->assertOk();
    $unread = test()->actingAs($bob)->getJson('/api/v1/notifications?status=unread')->assertOk();
    $count = test()->actingAs($bob)->getJson('/api/v1/notifications/unread-count')->assertOk();

    expect($list->json('data'))->toHaveCount($remaining)
        ->and($unread->json('data'))->toHaveCount($remaining)
        ->and($count->json('data.unread_count'))->toBe($remaining);

    foreach ([$list, $unread, $count] as $response) {
        expect($response->getContent())->not->toContain(SECRET_NAME)
            ->not->toContain(SECRET_TITLE)
            ->not->toContain('SEC-')
            ->not->toContain('"SEC"');

        foreach ($secretIds as $id) {
            expect($response->getContent())->not->toContain($id);
        }
    }

    // Each hidden notification is the same 404 as a random ID, with nothing in the body.
    foreach ($secretIds as $id) {
        $response = test()->actingAs($bob)->postJson("/api/v1/notifications/{$id}/read")->assertNotFound()->assertJsonPath('error.code', 'not_found');
        expect($response->getContent())->not->toContain(SECRET_NAME);
    }

    test()->actingAs($bob)->postJson('/api/v1/notifications/'.Str::uuid7().'/read')->assertNotFound();

    // "Mark all read" marks only what the member can see; the hidden rows are left exactly as they were.
    test()->actingAs($bob)->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.marked_read', $remaining);

    expect(InboxNotification::query()->whereKey($secretIds)->whereNotNull('read_at')->count())->toBe(0);
}

it('hides them from a member removed from the private project', function () {
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->secret->id}/members/{$this->bob->id}")->assertNoContent();

    expectSecretHidden($this->bob, $this->secretIds, 1);
    expect(inboxOf($this->bob)->json('data.0.project.key'))->toBe('WEB');
});

it('hides everything of the team from a member removed from it, or who leaves it', function (string $how) {
    $actor = $how === 'removed' ? $this->owner : $this->bob;
    $this->actingAs($actor)->deleteJson("/api/v1/teams/{$this->team->id}/members/{$this->bob->id}")->assertNoContent();

    expectSecretHidden($this->bob, $this->secretIds, 0);
    expect(test()->actingAs($this->bob)->getJson('/api/v1/notifications')->getContent())->not->toContain('Website')->not->toContain('Public work');
})->with(['removed', 'leaves']);

it('hides them when a team-visible project is made private without the member', function () {
    $ids = storedNotificationsOf($this->bob)->where('project_id', $this->open->id)->pluck('id')->all();

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->open->id}", ['visibility' => 'private'], taskIfMatch(1))->assertOk();

    $list = inboxOf($this->bob);
    expect($list->json('data'))->toHaveCount(3)
        ->and(collect($list->json('data'))->pluck('project.key')->unique()->all())->toBe(['SEC'])
        ->and($list->getContent())->not->toContain('Website')->not->toContain('Public work')
        ->and(unreadCountOf($this->bob))->toBe(3);

    $this->actingAs($this->bob)->postJson("/api/v1/notifications/{$ids[0]}/read")->assertNotFound();
});

it('hides them while the project is in the trash, and shows them again when it is recovered', function () {
    $this->actingAs($this->alice)->deleteJson("/api/v1/projects/{$this->secret->id}", [], taskIfMatch($this->secret->fresh()->version))->assertNoContent();

    expectSecretHidden($this->bob, $this->secretIds, 1);

    $this->actingAs($this->alice)->postJson("/api/v1/projects/{$this->secret->id}/restore-deleted")->assertOk();

    // Read at read time: the same rows come back, still unread (mark-all-read skipped them).
    expect(unreadCountOf($this->bob))->toBe(3);
});

it('hides the notifications of a trashed task', function () {
    $this->actingAs($this->alice)->deleteJson("/api/v1/tasks/{$this->secretTask->id}", [], taskIfMatch($this->secretTask))->assertNoContent();

    expectSecretHidden($this->bob, $this->secretIds, 1);
});

it('hides everything of a trashed team', function () {
    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}")->assertNoContent();

    expectSecretHidden($this->bob, $this->secretIds, 0);
});

it('hides private projects from a former Owner after an ownership transfer', function () {
    // The Owner sees the private project without a membership and gets notifications about it.
    notifyWatch($this->owner, $this->secretTask)->assertCreated();
    notifyEditTask($this->alice, $this->secretTask, ['title' => 'Classified launch v2'])->assertOk();
    $ownerSecret = storedNotificationsOf($this->owner)->where('project_id', $this->secret->id)->pluck('id')->all();
    expect($ownerSecret)->toHaveCount(1)->and(unreadCountOf($this->owner))->toBe(1);

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/ownership-transfer", ['new_owner_user_id' => $this->bob->id])->assertOk();

    $list = inboxOf($this->owner);
    expect($list->json('data'))->toBe([])
        ->and($list->getContent())->not->toContain(SECRET_NAME)
        ->and(unreadCountOf($this->owner))->toBe(0);
    $this->actingAs($this->owner)->postJson("/api/v1/notifications/{$ownerSecret[0]}/read")->assertNotFound();
});

it('keeps what a demoted member can still see, and shows nothing more', function () {
    // A Team Viewer still reads the projects they could see: an explicit private membership stays.
    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}/members/{$this->bob->id}", ['role' => 'viewer'])->assertOk();

    expect(unreadCountOf($this->bob))->toBe(4);

    // Demotion then removal from the private project: only the open project remains.
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->secret->id}/members/{$this->bob->id}")->assertNoContent();

    expectSecretHidden($this->bob, $this->secretIds, 1);
});

it('hides a mention once its comment is deleted', function () {
    $mention = storedNotificationsOf($this->bob)->firstWhere('type.value', 'comment.mentioned');
    $comment = $this->secretTask->comments()->firstOrFail();

    $this->actingAs($this->alice)->deleteJson("/api/v1/tasks/{$this->secretTask->id}/comments/{$comment->id}")->assertNoContent();

    expect(collect(inboxOf($this->bob)->json('data'))->pluck('id')->all())->not->toContain($mention->id)
        ->and(unreadCountOf($this->bob))->toBe(3);
});

it('hides a mention from a user an edit no longer mentions, and keeps it for those still mentioned', function () {
    $carol = taskTeamMember($this->team);
    $dave = taskTeamMember($this->team);
    $comment = notifyComment($this->alice, $this->openTask, "@{$carol->id} and @{$dave->id}")->json('data.id');
    expect(inboxTypes($carol))->toBe(['comment.mentioned'])->and(inboxTypes($dave))->toBe(['comment.mentioned']);

    // The comment still mentions someone (Dave), just not Carol any more.
    $this->actingAs($this->alice)->patchJson("/api/v1/tasks/{$this->openTask->id}/comments/{$comment}", ['body' => "Only @{$dave->id} now"])->assertOk();

    expect(inboxOf($carol)->json('data'))->toBe([])
        ->and(unreadCountOf($carol))->toBe(0)
        ->and(storedNotificationsOf($carol))->toHaveCount(1)
        ->and(inboxTypes($dave))->toBe(['comment.mentioned']);
});

it('shows a disabled account nothing, of any kind', function () {
    DB::table('users')->where('id', $this->bob->id)->update(['disabled_at' => now()]);
    $this->bob->refresh();

    expect(inboxOf($this->bob)->json('data'))->toBe([])
        ->and(unreadCountOf($this->bob))->toBe(0);
    $this->actingAs($this->bob)->postJson("/api/v1/notifications/{$this->secretIds[0]}/read")->assertNotFound();
    $this->actingAs($this->bob)->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.marked_read', 0);

    // Enabled again: everything is back, nothing was marked read meanwhile.
    DB::table('users')->where('id', $this->bob->id)->update(['disabled_at' => null]);
    expect(unreadCountOf($this->bob->refresh()))->toBe(4);
});

it('never shows a Team Admin outside the private project, or another team, anything about it', function () {
    // Rows that should never have been written (as if by a bug) are still invisible to them.
    $outsider = Team::factory()->create()->owner;

    foreach ([$this->admin, $outsider] as $user) {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid7(),
            'recipient_user_id' => $user->id,
            'team_id' => $this->team->id,
            'project_id' => $this->secret->id,
            'task_id' => $this->secretTask->id,
            'actor_user_id' => $this->alice->id,
            'type' => 'task.updated',
            'payload' => '{"fields":["title"]}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $list = inboxOf($user);
        expect($list->json('data'))->toBe([])
            ->and($list->getContent())->not->toContain(SECRET_NAME)
            ->and(unreadCountOf($user))->toBe(0);
    }
});

it('shows the notifications again to a member who is added back', function () {
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->secret->id}/members/{$this->bob->id}")->assertNoContent();
    expect(unreadCountOf($this->bob))->toBe(1);

    $this->actingAs($this->owner)->putJson("/api/v1/projects/{$this->secret->id}/members/{$this->bob->id}", ['role' => 'contributor'])->assertSuccessful();

    expect(unreadCountOf($this->bob))->toBe(4);
});

it('records nothing for a member who lost access before the delivery ran (send-time check)', function () {
    // Read, so that a new event could not collapse into an existing notification unnoticed.
    $this->actingAs($this->bob)->postJson('/api/v1/notifications/read-all')->assertOk();
    $before = storedNotificationsOf($this->bob)->map(fn (InboxNotification $n): array => [$n->id, $n->event_count])->all();
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->secret->id}/members/{$this->bob->id}")->assertNoContent();

    // The events of an edit and a comment made before the removal, delivered after it.
    app(Notifier::class)->taskChanged(new TaskChanged($this->secretTask->id, $this->secret->id, $this->team->id, $this->alice->id, ['title'], null));
    $comment = $this->secretTask->comments()->firstOrFail();
    app(Notifier::class)->commentAdded(new CommentAdded($comment->id, $this->secretTask->id, $this->secret->id, $this->team->id, $this->alice->id));

    expect(storedNotificationsOf($this->bob)->map(fn (InboxNotification $n): array => [$n->id, $n->event_count])->all())->toBe($before);
});

it('keeps the unread count on every page in step with the inbox', function () {
    $this->actingAs($this->bob)->get('/inbox')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Inbox/Index')
        ->where('inbox.unread_count', 4));

    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->secret->id}/members/{$this->bob->id}")->assertNoContent();

    $this->actingAs($this->bob)->get('/inbox')->assertInertia(fn (Assert $page) => $page->where('inbox.unread_count', 1));
});
