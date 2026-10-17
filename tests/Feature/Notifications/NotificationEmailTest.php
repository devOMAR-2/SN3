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

it('queues one encrypted email per window, after the commit, carrying only the notification ID and its window', function () {
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
        ->and($job->dueAt)->toBe(storedNotificationsOf($this->bob)->first()->email_due_at->utc()->toIso8601ZuluString())
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

/**
 * Runs one queued email job as the worker would.
 */
function runEmailJob(SendNotificationEmail $job): void
{
    $job->handle(app(Inbox::class), app(InboxPresenter::class));
}

it('keeps the recovery grace well above the delay and the retries, so a healthy window is never reopened', function () {
    $job = new SendNotificationEmail('00000000-0000-0000-0000-000000000000');
    $retries = array_sum($job->backoff);

    // Delay (120) + backoff (150) + a 5-minute margin for a busy worker.
    expect(SendNotificationEmail::RECOVERY_GRACE_SECONDS)->toBeGreaterThan(config()->integer('sn3.notifications.email_delay_seconds') + $retries + 300);
});

it('closes the window when every try failed, so the next event is emailed', function () {
    Queue::fake([SendNotificationEmail::class]);
    notifyWatch($this->bob, $this->task)->assertCreated();
    notifyEditTask($this->alice, $this->task, ['priority' => 'high'])->assertOk();
    Queue::assertPushed(SendNotificationEmail::class, 1);

    // The job's tries are used up (an SMTP error rolled each one back): failed() closes the window.
    $this->travel(3)->minutes();
    Queue::pushed(SendNotificationEmail::class)->first()->failed(new RuntimeException('smtp down'));
    expect(storedNotificationsOf($this->bob)->first()->email_due_at)->toBeNull();

    // Well within the recovery grace, the next event collapsed into the same unread row opens a new one.
    notifyEditTask($this->alice, $this->task->fresh(), ['title' => 'Renamed'])->assertOk();
    Queue::assertPushed(SendNotificationEmail::class, 2);
    expect(storedNotificationsOf($this->bob))->toHaveCount(1)
        ->and(storedNotificationsOf($this->bob)->first()->email_due_at)->not->toBeNull();
});

it('reopens a window whose job was lost once it is overdue by the grace, and emails once', function () {
    Queue::fake([SendNotificationEmail::class]);
    Notification::fake();
    notifyWatch($this->bob, $this->task)->assertCreated();
    notifyEditTask($this->alice, $this->task, ['priority' => 'high'])->assertOk();

    // The job never ran (a flushed queue). 2 minutes delay + 15 minutes grace later, the next event
    // queues a new one.
    $this->travel(18)->minutes();
    notifyEditTask($this->alice, $this->task->fresh(), ['title' => 'Renamed'])->assertOk();
    Queue::assertPushed(SendNotificationEmail::class, 2);
    $row = storedNotificationsOf($this->bob)->first();
    expect($row->email_due_at->isFuture())->toBeTrue();

    $this->travel(2)->minutes();
    runEmailJob(Queue::pushed(SendNotificationEmail::class)->last());
    Notification::assertSentTimes(InboxNotificationMail::class, 1);
    expect($row->fresh()->email_due_at)->toBeNull()->and($row->fresh()->emailed_at)->not->toBeNull();
});

it('never queues a second job for a healthy window, even past its due time within the grace', function () {
    Queue::fake([SendNotificationEmail::class]);
    notifyWatch($this->bob, $this->task)->assertCreated();
    notifyEditTask($this->alice, $this->task, ['priority' => 'high'])->assertOk();
    $due = storedNotificationsOf($this->bob)->first()->email_due_at;

    // Its job is waiting or retrying: 2 minutes past due, then 16 minutes after the event (just under
    // delay + grace), the window is still healthy.
    foreach ([4, 12] as $minutes) {
        $this->travel($minutes)->minutes();
        notifyEditTask($this->alice, $this->task->fresh(), ['title' => "Renamed {$minutes}"])->assertOk();
    }

    Queue::assertPushed(SendNotificationEmail::class, 1);
    expect(storedNotificationsOf($this->bob)->first()->email_due_at->equalTo($due))->toBeTrue()
        ->and(storedNotificationsOf($this->bob)->first()->event_count)->toBe(3);
});

it('lets a late job of a replaced window send nothing, so one window is never emailed twice or early', function () {
    Queue::fake([SendNotificationEmail::class]);
    Notification::fake();
    notifyWatch($this->bob, $this->task)->assertCreated();
    notifyEditTask($this->alice, $this->task, ['priority' => 'high'])->assertOk();

    // Worker down: job A (window W1) waits on the queue; recovery reopens W2 with job B.
    $this->travel(18)->minutes();
    notifyEditTask($this->alice, $this->task->fresh(), ['title' => 'R1'])->assertOk();
    [$a, $b] = Queue::pushed(SendNotificationEmail::class)->values()->all();

    // Worker back: A runs at once. W2 is not its window: nothing is sent, nothing changes.
    runEmailJob($a);
    Notification::assertNothingSent();
    expect(storedNotificationsOf($this->bob)->first()->email_due_at)->not->toBeNull();

    // B runs at W2's end and sends it, once.
    $this->travel(2)->minutes();
    runEmailJob($b);
    Notification::assertSentTimes(InboxNotificationMail::class, 1);

    // A new event opens W3 (job C); A or B running again changes nothing; C sends W3 at its end.
    $this->travel(10)->seconds();
    notifyEditTask($this->alice, $this->task->fresh(), ['title' => 'R2'])->assertOk();
    $c = Queue::pushed(SendNotificationEmail::class)->last();
    runEmailJob($b);
    runEmailJob($a);
    Notification::assertSentTimes(InboxNotificationMail::class, 1);
    $this->travel(2)->minutes();
    runEmailJob($c);
    Notification::assertSentTimes(InboxNotificationMail::class, 2);
});

it('lets a late failure close only its own window, never a reopened one that is already due', function () {
    Queue::fake([SendNotificationEmail::class]);
    Notification::fake();
    notifyWatch($this->bob, $this->task)->assertCreated();
    notifyEditTask($this->alice, $this->task, ['priority' => 'high'])->assertOk();
    $this->travel(18)->minutes();
    notifyEditTask($this->alice, $this->task->fresh(), ['title' => 'R1'])->assertOk();
    [$a, $b] = Queue::pushed(SendNotificationEmail::class)->values()->all();

    // W2 is due; B has not run yet (busy worker); A's last try fails now.
    $this->travel(125)->seconds();
    $a->failed(new RuntimeException('smtp'));
    expect(storedNotificationsOf($this->bob)->first()->email_due_at)->not->toBeNull();

    runEmailJob($b);
    Notification::assertSentTimes(InboxNotificationMail::class, 1);
});

/**
 * A job exactly as the class before `dueAt` serialized it (5b48ce7): no `dueAt` key at all, so
 * unserializing it never runs the constructor.
 */
function legacyEmailJob(string $notificationId): SendNotificationEmail
{
    $payload = 'O:30:"App\Jobs\SendNotificationEmail":2:{s:14:"notificationId";s:36:"'.$notificationId.'";s:11:"afterCommit";b:1;}';
    $job = unserialize($payload);
    assert($job instanceof SendNotificationEmail);

    return $job;
}

it('runs jobs queued before their window was recorded with the earlier rule', function () {
    Queue::fake([SendNotificationEmail::class]);
    Notification::fake();
    notifyAssign($this->alice, $this->task, $this->bob);
    $row = storedNotificationsOf($this->bob)->first();

    // Any open window is a legacy job's: it sends it.
    $this->travel(3)->minutes();
    $legacy = legacyEmailJob($row->id);
    expect($legacy->dueAt)->toBeNull();
    runEmailJob($legacy);
    Notification::assertSentTimes(InboxNotificationMail::class, 1);

    // Its failure closes a window that is due, and leaves one still in the future alone.
    DB::table('notifications')->where('id', $row->id)->update(['email_due_at' => now()->addMinute()]);
    legacyEmailJob($row->id)->failed(new RuntimeException('smtp'));
    expect($row->fresh()->email_due_at)->not->toBeNull();

    $this->travel(2)->minutes();
    legacyEmailJob($row->id)->failed(new RuntimeException('smtp'));
    expect($row->fresh()->email_due_at)->toBeNull();
});

it('sends nothing for a job whose window is already closed', function () {
    Queue::fake([SendNotificationEmail::class]);
    Notification::fake();
    notifyAssign($this->alice, $this->task, $this->bob);
    $job = Queue::pushed(SendNotificationEmail::class)->first();
    $this->travel(3)->minutes();

    runEmailJob($job);
    Notification::assertSentTimes(InboxNotificationMail::class, 1);

    // Run again (a leftover or retried copy) on the closed window: nothing more, for either kind of job.
    runEmailJob($job);
    runEmailJob(legacyEmailJob($job->notificationId));
    Notification::assertSentTimes(InboxNotificationMail::class, 1);
});
