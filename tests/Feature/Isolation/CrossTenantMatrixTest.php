<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/IsolationWorld.php';

// CORE-011 acceptance ("two teams + private projects, direct URL/API, search, files, inbox"; AUTH-01,
// AUTH-02; AGENTS.md §6 rules 1–3, §8): every route that names a tenant resource, called for every
// resource of the seeded world by every user who may not see it, answers 404, discloses none of the
// resource's content (its canaries, its team's name) and changes nothing. The same URL works for a user
// who may see it, so a 404 here is never just a wrong URL.

uses(RefreshDatabase::class);

dataset('scoped routes', fn () => array_combine(IsolationRoutes::SCOPED, array_map(fn (string $key): array => [$key], IsolationRoutes::SCOPED)));

it('refuses the resource to every user who may not see it, with a bare 404, and changes nothing', function (string $key) {
    $world = isolationWorld();
    $route = isolationRoute($key);
    $method = explode(' ', $key, 2)[0];
    $failures = [];

    foreach (isolationTargets($world, $route) as $target) {
        $url = isolationUrl($route, $target['params']);

        foreach ($target['hidden'] as $userKey) {
            $before = isolationSnapshot();
            $response = isolationCall($world['users'][$userKey], $method, $url);
            $body = (string) $response->getContent();

            if ($response->getStatusCode() !== 404) {
                $failures[] = "{$userKey} → {$target['label']}: {$response->getStatusCode()}";
            }

            foreach (($target['canaries'])($userKey) as $canary) {
                if (str_contains($body, $canary)) {
                    $failures[] = "{$userKey} → {$target['label']}: body contains {$canary}";
                }
            }

            if (isolationSnapshot() !== $before) {
                $failures[] = "{$userKey} → {$target['label']}: data changed";
            }

            app('auth')->forgetGuards();
        }
    }

    expect($failures)->toBe([]);
})->with('scoped routes');

it('reaches the resource with the same URL for a user who may see it', function (string $key) {
    $world = isolationWorld();
    $route = isolationRoute($key);
    $method = explode(' ', $key, 2)[0];
    $failures = [];

    foreach (isolationTargets($world, $route) as $target) {
        if ($target['reader'] === null) {
            continue;
        }

        $response = isolationCall($world['users'][$target['reader']], $method, isolationUrl($route, $target['params']));

        if (in_array($response->getStatusCode(), [404, 405], true)) {
            $failures[] = "{$key}: {$target['reader']} → {$target['label']}: {$response->getStatusCode()}";
        }

        app('auth')->forgetGuards();
    }

    expect($failures)->toBe([]);
})->with('scoped routes');

it('sends guests to sign in without disclosing anything', function (string $key) {
    $world = isolationWorld();
    $route = isolationRoute($key);
    $method = explode(' ', $key, 2)[0];

    foreach (isolationTargets($world, $route) as $target) {
        $url = isolationUrl($route, $target['params']);
        $headers = ['Accept' => str_starts_with($url, '/api/') ? 'application/json' : 'text/html'];
        $response = $this->call($method, $url, [], [], [], $this->transformHeadersToServerVars($headers));

        expect($response->getStatusCode())->toBeIn(str_starts_with($url, '/api/') ? [401, 419] : [302, 419], "{$target['label']}");

        foreach (($target['canaries'])('outsider') as $canary) {
            expect((string) $response->getContent())->not->toContain($canary);
        }
    }
})->with('scoped routes');
