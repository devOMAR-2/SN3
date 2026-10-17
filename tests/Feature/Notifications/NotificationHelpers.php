<?php

// Shared helpers of the CORE-009 notification tests (required by each file; not a test file itself).

require_once __DIR__.'/../Tasks/TaskHelpers.php';

use App\Models\InboxNotification;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;

/**
 * GET /api/v1/notifications as `$user`.
 *
 * @param  array<string, string|int>  $query
 */
function inboxOf(User $user, array $query = []): TestResponse
{
    $suffix = $query === [] ? '' : '?'.http_build_query($query);

    return test()->actingAs($user)->getJson('/api/v1/notifications'.$suffix)->assertOk();
}

function unreadCountOf(User $user): int
{
    return (int) test()->actingAs($user)->getJson('/api/v1/notifications/unread-count')->assertOk()->json('data.unread_count');
}

/**
 * PATCH /api/v1/tasks/{task} with the current version.
 *
 * @param  array<string, mixed>  $changes
 */
function notifyEditTask(User $actor, Task $task, array $changes): TestResponse
{
    return test()->actingAs($actor)->patchJson("/api/v1/tasks/{$task->id}", $changes, taskIfMatch($task));
}

function notifyAssign(User $actor, Task $task, ?User $assignee): TestResponse
{
    return notifyEditTask($actor, $task, ['assignee_user_id' => $assignee?->id])->assertOk();
}

function notifyWatch(User $user, Task $task): TestResponse
{
    return test()->actingAs($user)->postJson("/api/v1/tasks/{$task->id}/watchers");
}

function notifyComment(User $author, Task $task, string $body = 'A comment'): TestResponse
{
    return test()->actingAs($author)->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => $body])->assertCreated();
}

/**
 * Every stored notification of the user, whether they may see it or not.
 *
 * @return Collection<int, InboxNotification>
 */
function storedNotificationsOf(User $user): Collection
{
    return InboxNotification::query()->where('recipient_user_id', $user->id)->orderBy('created_at')->orderBy('id')->get();
}

/**
 * The types in the user's visible inbox, newest first.
 *
 * @return list<string>
 */
function inboxTypes(User $user): array
{
    return array_values(array_map(fn (array $item): string => (string) $item['type'], inboxOf($user)->json('data')));
}
