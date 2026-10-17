<?php

require_once __DIR__.'/NotificationHelpers.php';

use App\Domain\Notifications\Inbox;
use App\Domain\Notifications\InboxPresenter;
use App\Jobs\SendNotificationEmail;
use App\Models\InboxNotification;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Inbox\InboxNotificationMail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

// CORE-009: notification emails (API_SPEC.md §9 "Email notifications are queued and respect per-user
// preferences"; FR-COL-004, FR-I18N-001; ADR-029 §5). Queued once per window, encrypted, IDs only;
// re-checked when sent; in the recipient's language; user-written text is inert Markdown.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->alice = taskTeamMember($this->team);
    $this->bob = taskTeamMember($this->team);
    $this->project = taskProject($this->team, attributes: ['project_key' => 'WEB', 'name' => 'Website']);
    $this->task = taskIn($this->project, ['title' => 'Fix login']);
});

function runEmailJobs(): void
{
    foreach (Queue::pushed(SendNotificationEmail::class) as $job) {
        $job->handle(app(Inbox::class), app(InboxPresenter::class));
    }
}

it('emails the recipient in their language, once the window ends, with the live title', function () {
    Notification::fake();
    $this->bob->forceFill(['locale' => 'ar'])->save();

    notifyAssign($this->alice, $this->task, $this->bob);

    Notification::assertSentTo($this->bob, InboxNotificationMail::class, function (InboxNotificationMail $mail, array $channels, User $notifiable, ?string $locale): bool {
        return $channels === ['mail']
            && $locale === 'ar'
            && $mail->view['type'] === 'task.assigned'
            && $mail->view['task']['title'] === 'Fix login';
    });
    Notification::assertSentTimes(InboxNotificationMail::class, 1);

    $row = storedNotificationsOf($this->bob)->first();
    expect($row->emailed_at)->not->toBeNull()->and($row->email_due_at)->toBeNull();
});

it('writes the email in Arabic or English, and user-written text as plain text', function (string $locale, string $headline) {
    $this->alice->forceFill(['name' => '[Verify now](https://evil.example/login)'])->save();
    $this->task->forceFill(['title' => '![x](https://evil.example/p.png) <b>bold</b>'])->save();
    $view = app(InboxPresenter::class)->one(InboxNotification::query()->forceCreate([
        'recipient_user_id' => $this->bob->id,
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'task_id' => $this->task->id,
        'actor_user_id' => $this->alice->id,
        'type' => 'task.updated',
        'payload' => ['fields' => ['priority', 'title']],
        'event_count' => 1,
    ]));

    app()->setLocale($locale);
    $html = (string) (new InboxNotificationMail($view))->toMail($this->bob)->render();

    expect($html)->toContain($headline)
        ->and($html)->not->toContain('href="https://evil.example')
        ->and($html)->not->toContain('src="https://evil.example')
        ->and($html)->not->toContain('<b>bold</b>')
        ->and($html)->toContain('Verify now')
        ->and($html)->toContain('href="'.url('/tasks/'.$this->task->id).'"');
})->with([
    'English' => ['en', 'updated WEB-1'],
    'Arabic' => ['ar', 'المهمة WEB-1'],
]);

it('queues one encrypted email per window, after the commit, carrying only the notification ID', function () {
    Queue::fake();
    notifyWatch($this->bob, $this->task)->assertCreated();

    notifyEditTask($this->alice, $this->task, ['title' => 'Secret new title'])->assertOk();
    notifyEditTask($this->alice, $this->task, ['priority' => 'high'])->assertOk();
    // The fan-out listener ran on the (faked) queue: deliver it.
    foreach (Queue::pushed(CallQueuedListener::class) as $listener) {
        app()->call([app($listener->class), $listener->method], ['event' => $listener->data[0]]);
    }

    Queue::assertPushed(SendNotificationEmail::class, 1);
    $job = Queue::pushed(SendNotificationEmail::class)->first();

    expect($job)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($job->afterCommit)->toBeTrue()
        ->and($job->delay)->not->toBeNull()
        ->and(serialize($job))->not->toContain('Secret new title')
        ->and(storedNotificationsOf($this->bob)->first()->event_count)->toBe(2);
});

it('sends nothing for a notification read in the inbox before the window ended, and closes the window', function () {
    Queue::fake([SendNotificationEmail::class]);
    Notification::fake();

    notifyAssign($this->alice, $this->task, $this->bob);
    $row = storedNotificationsOf($this->bob)->first();
    $this->actingAs($this->bob)->postJson("/api/v1/notifications/{$row->id}/read")->assertOk();

    runEmailJobs();

    Notification::assertNothingSent();
    expect($row->fresh()->email_due_at)->toBeNull()->and($row->fresh()->emailed_at)->toBeNull();
});

it('sends nothing to a recipient who lost access before the window ended', function () {
    Queue::fake([SendNotificationEmail::class]);
    Notification::fake();
    $private = taskProject($this->team, 'private');
    taskProjectMember($private, $this->alice);
    taskProjectMember($private, $this->bob);
    $task = taskIn($private);

    notifyAssign($this->alice, $task, $this->bob);
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$private->id}/members/{$this->bob->id}")->assertNoContent();

    runEmailJobs();

    Notification::assertNothingSent();
});

it('respects the email preference at send time, and emails users who only turned the inbox off', function () {
    Queue::fake([SendNotificationEmail::class]);
    Notification::fake();
    $carol = taskTeamMember($this->team);
    $this->actingAs($this->bob)->patchJson('/api/v1/me', ['in_app_notifications' => false])->assertOk();

    notifyAssign($this->alice, $this->task, $this->bob);
    notifyAssign($this->alice, taskIn($this->project), $carol);
    // Carol turns email off after the email was queued.
    $this->actingAs($carol)->patchJson('/api/v1/me', ['email_notifications' => false])->assertOk();

    runEmailJobs();

    Notification::assertSentTo($this->bob, InboxNotificationMail::class);
    Notification::assertNotSentTo($carol, InboxNotificationMail::class);
});

it('queues no email for users with email off, for invitations, or for disabled accounts', function () {
    Queue::fake([SendNotificationEmail::class]);
    $this->actingAs($this->bob)->patchJson('/api/v1/me', ['email_notifications' => false])->assertOk();
    notifyAssign($this->alice, $this->task, $this->bob);

    User::factory()->create(['email' => 'invitee@example.com']);
    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/invitations", ['type' => 'email', 'email' => 'invitee@example.com', 'role' => 'member'], taskIdempotencyHeader())->assertCreated();

    Queue::assertNotPushed(SendNotificationEmail::class);

    // Disabled after the email was queued: nothing is sent.
    Notification::fake();
    $carol = taskTeamMember($this->team);
    notifyAssign($this->alice, taskIn($this->project), $carol);
    DB::table('users')->where('id', $carol->id)->update(['disabled_at' => now()]);
    runEmailJobs();

    Notification::assertNothingSent();
});
