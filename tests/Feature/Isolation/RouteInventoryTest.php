<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/IsolationWorld.php';

// CORE-011: the isolation suite covers every route. A new route must be listed in IsolationRoutes, either
// as SCOPED (it names a tenant resource and is then called by CrossTenantMatrixTest and
// GuessedIdentifierTest for every seeded resource) or as OPEN with its kind.

uses(RefreshDatabase::class);

it('lists every registered route exactly once, and nothing else', function () {
    $registered = [];

    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        // Laravel's signed local-disk route (filesystems.php `local` serve: true); attachments and exports
        // are never served this way (`serve: false`). Matched exactly, so no application route is skipped.
        if ($route->uri() === 'storage/{path}') {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $registered[] = "{$method} {$route->uri()}";
        }
    }

    $listed = [...IsolationRoutes::SCOPED, ...array_keys(IsolationRoutes::OPEN)];
    sort($registered);
    sort($listed);

    expect(array_values(array_diff($registered, $listed)))->toBe([], 'Routes missing from IsolationRoutes')
        ->and(array_values(array_diff($listed, $registered)))->toBe([], 'Stale entries in IsolationRoutes')
        ->and(count($listed))->toBe(count(array_unique($listed)));
});

it('knows a value for every parameter of a scoped route, and no open route names a tenant resource', function () {
    $world = isolationWorld();

    foreach (IsolationRoutes::SCOPED as $key) {
        $route = isolationRoute($key);

        foreach (isolationTargets($world, $route) as $target) {
            expect(isolationUrl($route, $target['params']))->toBeString();
        }
    }

    $tenant = ['team', 'teamSlug', 'project', 'projectKey', 'task', 'trashedTask', 'trashedProject', 'trashedTeam', 'comment',
        'attachment', 'label', 'item', 'status', 'member', 'export', 'invitation', 'notification'];

    foreach (array_keys(IsolationRoutes::OPEN) as $key) {
        expect(array_intersect(isolationRoute($key)->parameterNames(), $tenant))->toBe([], $key);
    }

    expect(array_unique(array_values(IsolationRoutes::OPEN)))->each->toBeIn(['self', 'guest', 'token', 'health']);
});

it('finds a hidden value however a response encodes it', function () {
    $name = 'فريق ألفا';
    $page = '<div data-page="'.htmlspecialchars((string) json_encode(['props' => ['team' => $name, 'url' => '/t/a/b']]), ENT_QUOTES).'"></div>';

    expect(isolationLeaks((string) json_encode(['name' => $name]), [$name]))->toBe([$name])
        ->and(isolationLeaks($page, [$name]))->toBe([$name])
        ->and(isolationLeaks((string) json_encode(['path' => 'a/b']), ['a/b']))->toBe(['a/b'])
        ->and(isolationLeaks('{"name":"Beta Studio"}', ['فريق ألفا']))->toBe([])
        ->and(isolationLeaks(isolationWithoutEcho($page, '/t/a/b'), ['/t/a/b']))->toBe([]);
});
