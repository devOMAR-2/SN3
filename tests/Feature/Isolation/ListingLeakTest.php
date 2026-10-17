<?php

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/IsolationWorld.php';

// CORE-011 (AUTH-02 "absent from list/search", NOT-01, AGENTS.md §6 rule 2): for every seeded user, every
// page and list they can open (their own data, their teams, the projects they can see, search included)
// is crawled, and no response contains a canary of something they may not see. Then the counts that
// could leak by number: the unread count and search hits.

uses(RefreshDatabase::class);

/**
 * Every GET the user may make, as URLs: the `self` routes, the GET routes of their teams and of the
 * projects they can see, and searches for every canary.
 *
 * @return list<string>
 */
function isolationCrawl(array $world, string $userKey): array
{
    $urls = [];

    foreach (IsolationRoutes::OPEN as $key => $kind) {
        [$method, $uri] = explode(' ', $key, 2);
        if ($kind === 'self' && $method === 'GET') {
            $urls[] = '/'.ltrim($uri, '/');
        }
    }

    $canaries = [...array_column($world['projects'], 'canary'), ...array_column($world['teams'], 'canary')];
    $urls[] = '/api/v1/notifications?status=unread';

    foreach (IsolationRoutes::SCOPED as $key) {
        [$method] = explode(' ', $key, 2);
        if ($method !== 'GET') {
            continue;
        }

        $route = isolationRoute($key);
        foreach (isolationTargets($world, $route) as $target) {
            if (isolationScope($route) === 'notification' || in_array($userKey, $target['hidden'], true)) {
                continue;
            }

            $url = isolationUrl($route, $target['params']);
            $urls[] = $url;

            if (str_ends_with($url, '/tasks') && str_contains($url, '/projects/')) {
                // Search by text, by number and by key (TaskQuery::search has a branch for each).
                foreach ([...$canaries, '1', ...array_map(fn (array $p): string => $p['model']->project_key.'-1', $world['projects'])] as $q) {
                    $urls[] = "{$url}?q=".rawurlencode($q);
                }
            }
        }
    }

    return array_values(array_unique($urls));
}

it('never shows a user anything of a project or team they may not see, anywhere they can look', function () {
    $world = isolationWorld();
    $failures = [];

    foreach ($world['users'] as $userKey => $user) {
        $hidden = isolationHiddenCanaries($world, $userKey);

        foreach (isolationCrawl($world, $userKey) as $url) {
            $response = isolationCall($user, 'GET', $url);
            $body = isolationWithoutEcho((string) $response->getContent(), $url);

            if ($response->getStatusCode() >= 500) {
                $failures[] = "{$userKey} {$url}: {$response->getStatusCode()}";
            }

            foreach (isolationLeaks($body, $hidden) as $leak) {
                $failures[] = "{$userKey} {$url}: contains {$leak}";
            }

            app('auth')->forgetGuards();
        }
    }

    expect($failures)->toBe([]);
});

it('finds each visible task by search (text, number, key), and no other', function () {
    $world = isolationWorld();

    foreach ($world['users'] as $userKey => $user) {
        foreach ($world['projects'] as $projectKey => $project) {
            if (! isolationCanSee($world, $userKey, $projectKey)) {
                continue;
            }

            foreach ($world['projects'] as $otherKey => $other) {
                $titles = $this->actingAs($user)->getJson("/api/v1/projects/{$project['model']->id}/tasks?q={$other['canary']}")
                    ->assertOk()->json('data.*.title');
                sort($titles);
                $expected = $otherKey === $projectKey
                    ? Task::query()->where('project_id', $project['model']->id)->where('title', 'like', "%{$other['canary']}%")->orderBy('title')->pluck('title')->all()
                    : [];

                expect($titles)->toBe($expected, "{$userKey} searching {$projectKey} for {$otherKey}")
                    ->and(count($titles))->toBeGreaterThanOrEqual($otherKey === $projectKey ? 1 : 0);
            }
        }

        // By number and by key: only the project's own task with that number, never another project's.
        foreach ($world['projects'] as $projectKey => $project) {
            if (! isolationCanSee($world, $userKey, $projectKey)) {
                continue;
            }

            $own = Task::query()->where('project_id', $project['model']->id)->where('task_number', 1)->pluck('id')->all();
            foreach (['1', ...array_map(fn (array $p): string => $p['model']->project_key.'-1', $world['projects'])] as $q) {
                $ids = $this->actingAs($user)->getJson("/api/v1/projects/{$project['model']->id}/tasks?q=".rawurlencode($q))->assertOk()->json('data.*.id');
                $expected = $q === '1' || $q === $project['model']->project_key.'-1' ? $own : [];

                expect($ids)->toBe($expected, "{$userKey} searching {$projectKey} for {$q}");
            }
        }

        $assigned = $this->actingAs($user)->getJson('/api/v1/me/tasks')->assertOk()->json('data.*.title');
        $expected = [];
        foreach ($world['projects'] as $projectKey => $project) {
            $assignee = collect($project['notifications'])->firstWhere('type', 'task.assigned')['to'] ?? null;
            if ($assignee === $userKey && isolationCanSee($world, $userKey, $projectKey)) {
                $expected[] = "Task {$project['canary']}";
            }
        }
        sort($assigned);
        expect($assigned)->toBe($expected, "{$userKey} my tasks");
    }
});

it('counts and lists only the notifications a user may still see (NOT-01)', function () {
    $world = isolationWorld();

    foreach ($world['users'] as $userKey => $user) {
        $expected = [];
        foreach ($world['projects'] as $projectKey => $project) {
            foreach ($project['notifications'] as $note) {
                if ($note['to'] === $userKey && isolationCanSee($world, $userKey, $projectKey)) {
                    $expected[] = $note['id'];
                }
            }
        }
        sort($expected);

        $listed = $this->actingAs($user)->getJson('/api/v1/notifications')->assertOk()->json('data.*.id');
        sort($listed);

        expect($listed)->toBe($expected, "{$userKey} inbox")
            ->and($this->actingAs($user)->getJson('/api/v1/notifications/unread-count')->assertOk()->json('data.unread_count'))
            ->toBe(count($expected), "{$userKey} unread count");
    }

    // The two users who were mentioned in a private project they cannot see have something hidden.
    expect(collect($world['projects'])->flatMap(fn (array $p): array => array_column($p['notifications'], 'to'))->unique()->values()->all())
        ->toContain('alpha_admin', 'shared');
});
