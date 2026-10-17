<?php

// The CORE-011 isolation world (required by each Isolation test file; not a test file itself).
//
// Built on the deterministic E2E tenants (e2e/fixtures/tenants.json, E2ETenantSeeder): two unrelated
// teams, every role, a user in both teams (`shared`, a Viewer of alpha and an Admin of beta) and one in
// none; per team a team-visible and a private project whose Admin has no membership. Every text field of
// a seeded project carries its canary. This file adds what has no fixed ID in the fixture (a trashed team,
// project and task, a team export, an invitation) and the access rules the suite checks against.

use App\Domain\Exports\TeamExportStatus;
use App\Domain\Invitations\InvitationToken;
use App\Domain\Projects\ProjectVisibility;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamExport;
use App\Models\TeamInvitation;
use App\Models\User;
use Database\Seeders\E2ETenantSeeder;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Seeds the world and returns it: users, teams and projects by fixture key, and per team and project the
 * values a route's parameters take. Extra rows carry canaries too: `<project canary>-TRASHED-TASK`,
 * `<team key>-TRASHED-PROJECT`, `<team key>-TRASHED-TEAM`.
 *
 * @return array{users: array<string, User>, teams: array<string, array<string, mixed>>, projects: array<string, array<string, mixed>>}
 */
function isolationWorld(): array
{
    Storage::fake('attachments');
    Storage::fake((string) config('sn3.exports.disk'));
    Queue::fake();
    test()->seed(E2ETenantSeeder::class);
    $fixture = E2ETenantSeeder::fixture();

    $users = [];
    foreach ($fixture['users'] as $data) {
        $users[$data['key']] = User::query()->findOrFail($data['id']);
    }

    $teams = [];
    foreach ($fixture['teams'] as $data) {
        $team = Team::query()->findOrFail($data['id']);
        $owner = $users[$data['owner']];
        $members = [$data['owner'] => 'owner', ...$data['members']];
        $canary = 'CNRY-'.strtoupper($data['key']);

        $trashedProject = Project::factory()->for($team)->withDefaultStatuses()->create([
            'name' => "{$canary}-TRASHED-PROJECT",
            'visibility' => ProjectVisibility::Team,
        ]);
        $trashedProject->forceFill(['deleted_at' => now()])->save();

        $trashedTeam = Team::factory()->ownedBy($owner)->create(['name' => "{$canary}-TRASHED-TEAM"]);
        $trashedTeam->forceFill(['deleted_at' => now()])->save();

        $export = new TeamExport;
        $export->forceFill([
            'team_id' => $team->id,
            'requested_by' => $owner->id,
            'status' => TeamExportStatus::Ready,
            'format_version' => 1,
            'storage_key' => "exports/{$team->id}/".Str::uuid().'.zip',
            'size_bytes' => 4,
            'sha256' => str_repeat('0', 64),
            'completed_at' => now(),
            'expires_at' => now()->addDay(),
        ])->save();

        $invitation = new TeamInvitation;
        $invitation->forceFill([
            'team_id' => $team->id,
            'email' => null,
            'invited_role' => 'member',
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
            'max_uses' => TeamInvitation::LINK_MAX_USES,
            'use_count' => 0,
        ]);
        $invitation->setAttribute('token_hash', DB::raw("decode('".InvitationToken::generate()['hash']."', 'hex')"));
        $invitation->save();

        $teams[$data['key']] = [
            'model' => $team,
            'canary' => $canary,
            'members' => $members,
            'params' => [
                'team' => $team->id,
                'teamSlug' => $team->slug,
                'member' => $users[array_key_last($members)]->id,
                'export' => $export->id,
                'invitation' => $invitation->id,
                'trashedProject' => $trashedProject->id,
                'trashedTeam' => $trashedTeam->id,
            ],
        ];
    }

    $projects = [];
    foreach ($fixture['projects'] as $data) {
        $project = Project::query()->findOrFail($data['ids']['project']);
        $task = Task::query()->findOrFail($data['ids']['task']);
        $trashedTask = Task::factory()->for($project)->create(['title' => "{$data['canary']}-TRASHED-TASK"]);
        $trashedTask->delete();

        // A stale assignment: the private project's task assigned to the team's Admin, who has no
        // membership. Losing access unassigns (ADR-023 §5), so this state is not reachable through the
        // API; it proves that lists like /me/tasks filter by visibility, not by assignment alone.
        if ($data['visibility'] === 'private') {
            $admin = array_search('admin', $teams[$data['team']]['members'], true);
            Task::factory()->for($project)->assignedTo($users[$admin])->create(['title' => "{$data['canary']}-STALE-ASSIGNMENT"]);
        }

        // A project member to remove or change (team projects have none in the fixture), and the Team
        // Owner watching the task, so the member and unwatch routes have something to act on.
        $memberKey = array_key_first($data['members']) ?? array_search('member', $teams[$data['team']]['members'], true);
        if ($data['members'] === []) {
            ProjectMembership::factory()->for($project)->member($users[$memberKey])->create();
        }
        DB::table('task_watchers')->insert([
            'task_id' => $task->id,
            'project_id' => $project->id,
            'team_id' => $project->team_id,
            'user_id' => $project->team->owner_user_id,
            'created_at' => now(),
        ]);

        $projects[$data['key']] = [
            'model' => $project,
            'team' => $data['team'],
            'canary' => $data['canary'],
            'visibility' => $data['visibility'],
            'members' => array_keys($data['members']),
            'notifications' => $data['notifications'],
            'params' => [
                ...$teams[$data['team']]['params'],
                'project' => $project->id,
                'projectKey' => $project->project_key,
                'task' => $task->id,
                'trashedTask' => $trashedTask->id,
                'comment' => $data['ids']['comment'],
                'attachment' => $data['ids']['attachment'],
                'label' => $data['ids']['label'],
                'item' => $data['ids']['checklist_item'],
                'status' => $data['ids']['status'],
                'member' => $users[$memberKey]->id,
            ],
        ];
    }

    return ['users' => $users, 'teams' => $teams, 'projects' => $projects];
}

/** Whether the fixture user belongs to the team. */
function isolationInTeam(array $world, string $userKey, string $teamKey): bool
{
    return array_key_exists($userKey, $world['teams'][$teamKey]['members']);
}

/**
 * Whether the user may see the project (API_SPEC.md §12, DECIDED-003): a team project by any member of its
 * team; a private one by the Team Owner and explicit members only.
 */
function isolationCanSee(array $world, string $userKey, string $projectKey): bool
{
    $project = $world['projects'][$projectKey];
    $role = $world['teams'][$project['team']]['members'][$userKey] ?? null;

    return match (true) {
        $role === null => false,
        $project['visibility'] === 'team' => true,
        default => $role === 'owner' || in_array($userKey, $project['members'], true),
    };
}

/**
 * Every canary the user may not see: projects they cannot see, and the extra rows of teams they are not in
 * (the trashed rows of their own teams are theirs to see in the trash, so they are not counted).
 *
 * @return list<string>
 */
function isolationHiddenCanaries(array $world, string $userKey): array
{
    $hidden = [];

    foreach ($world['projects'] as $key => $project) {
        if (! isolationCanSee($world, $userKey, $key)) {
            $hidden[] = $project['canary'];
        }
    }

    foreach ($world['teams'] as $key => $team) {
        if (! isolationInTeam($world, $userKey, $key)) {
            $hidden[] = $team['canary'];
            $hidden[] = $team['model']->name;
        }
    }

    return $hidden;
}

/**
 * The URL of a route with its parameters filled in from `$params`; signed routes are signed for real, so
 * the signature is never what refuses the request.
 *
 * @param  array<string, string>  $params
 */
function isolationUrl(Route $route, array $params): string
{
    $values = [];
    foreach ($route->parameterNames() as $name) {
        if (! array_key_exists($name, $params)) {
            throw new LogicException("No value for {{$name}} in {$route->uri()}.");
        }
        $values[$name] = $params[$name];
    }

    if (in_array('signed', $route->gatherMiddleware(), true)) {
        return URL::temporarySignedRoute((string) $route->getName(), now()->addHour(), $values, absolute: false);
    }

    return '/'.ltrim((string) preg_replace_callback('/\{(\w+)\??\}/', fn (array $m): string => rawurlencode($values[$m[1]]), $route->uri()), '/');
}

/** Sends a request the way the application's own client does (JSON, write headers on writes). */
function isolationCall(User $actor, string $method, string $url): TestResponse
{
    $headers = ['Accept' => str_starts_with($url, '/api/') ? 'application/json' : 'text/html'];

    if ($method !== 'GET') {
        $headers['Idempotency-Key'] = (string) Str::uuid();
        $headers['If-Match'] = '"1"';
    }

    return test()->actingAs($actor)->call($method, $url, [], [], [], test()->transformHeadersToServerVars($headers));
}

/**
 * A fingerprint of everything the suite could change: each table's rows, in a stable order.
 */
function isolationSnapshot(): string
{
    $tables = ['teams', 'team_memberships', 'team_invitations', 'team_exports', 'projects', 'project_memberships',
        'project_statuses', 'labels', 'tasks', 'task_labels', 'task_checklist_items', 'task_comments',
        'task_comment_mentions', 'task_attachments', 'task_watchers', 'notifications', 'task_activities', 'user_preferences', 'users'];

    return md5(implode('|', array_map(
        fn (string $table): string => (string) DB::selectOne("SELECT md5(coalesce(string_agg(t::text, ',' ORDER BY t::text), '')) AS h FROM {$table} t")->h,
        $tables,
    )));
}

require_once __DIR__.'/IsolationRoutes.php';

/** The registered route for an `IsolationRoutes` key (`METHOD uri`). */
function isolationRoute(string $key): Route
{
    [$method, $uri] = explode(' ', $key, 2);

    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
            return $route;
        }
    }

    throw new LogicException("No route {$key}.");
}

/** What a scoped route names: a notification, a project (or something in one), or a team. */
function isolationScope(Route $route): string
{
    $names = $route->parameterNames();

    return match (true) {
        in_array('notification', $names, true) => 'notification',
        array_intersect($names, ['project', 'projectKey', 'task', 'trashedTask', 'comment', 'attachment', 'label', 'item', 'status']) !== [] => 'project',
        default => 'team',
    };
}

/**
 * The resources a scoped route is called for, each with the users who may not see it, the user who may
 * (the positive control), and the canaries a refused response must not contain.
 *
 * @return list<array{label: string, params: array<string, string>, hidden: list<string>, reader: string|null, canaries: Closure(string): list<string>}>
 */
function isolationTargets(array $world, Route $route): array
{
    $userKeys = array_keys($world['users']);
    $targets = [];

    foreach (match (isolationScope($route)) {
        'project' => array_keys($world['projects']),
        'team' => array_keys($world['teams']),
        'notification' => array_keys($world['projects']),
    } as $key) {
        if (isolationScope($route) === 'team') {
            $team = $world['teams'][$key];
            $projectCanaries = array_values(array_map(fn (array $p): string => $p['canary'], array_filter($world['projects'], fn (array $p): bool => $p['team'] === $key)));
            $targets[] = [
                'label' => "team {$key}",
                'params' => $team['params'],
                'hidden' => array_values(array_filter($userKeys, fn (string $u): bool => ! isolationInTeam($world, $u, $key))),
                'reader' => array_search('owner', $team['members'], true),
                'canaries' => fn (string $u): array => [$team['canary'], $team['model']->name, ...$projectCanaries],
            ];

            continue;
        }

        $project = $world['projects'][$key];
        $team = $world['teams'][$project['team']];
        $canaries = fn (string $u): array => isolationInTeam($world, $u, $project['team'])
            ? [$project['canary']]
            : [$project['canary'], $team['canary'], $team['model']->name];

        if (isolationScope($route) === 'notification') {
            foreach ($project['notifications'] as $note) {
                $visible = isolationCanSee($world, $note['to'], $key);
                $targets[] = [
                    'label' => "notification {$note['id']} ({$key} → {$note['to']})",
                    'params' => ['notification' => $note['id']],
                    // Nobody else ever sees it; its recipient only while they can see the project (NOT-01).
                    'hidden' => array_values(array_filter($userKeys, fn (string $u): bool => $u !== $note['to'] || ! $visible)),
                    'reader' => $visible ? $note['to'] : null,
                    'canaries' => $canaries,
                ];
            }

            continue;
        }

        $targets[] = [
            'label' => "project {$key}",
            'params' => $project['params'],
            'hidden' => array_values(array_filter($userKeys, fn (string $u): bool => ! isolationCanSee($world, $u, $key))),
            'reader' => array_search('owner', $team['members'], true),
            'canaries' => $canaries,
        ];
    }

    return $targets;
}
