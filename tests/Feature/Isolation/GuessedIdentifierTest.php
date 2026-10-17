<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/IsolationWorld.php';

// CORE-011 (AGENTS.md §6 rule 1 "guessing UUIDs cannot bypass scoping", §8 "guessed identifiers"): for
// every scoped route, another tenant's real resource, a random UUID and malformed identifiers all get the
// same answer, so a response never tells whether something exists elsewhere.

uses(RefreshDatabase::class);

dataset('scoped routes', fn () => array_combine(IsolationRoutes::SCOPED, array_map(fn (string $key): array => [$key], IsolationRoutes::SCOPED)));

/** The parts of a refusal that may differ between two requests (request IDs, CSRF tokens, asset versions). */
function isolationRefusalShape(TestResponse $response): array
{
    $json = json_decode((string) $response->getContent(), true);

    if (is_array($json)) {
        return ['status' => $response->getStatusCode(), 'code' => $json['error']['code'] ?? null, 'message' => $json['error']['message'] ?? null];
    }

    preg_match('/data-page="([^"]*)"/', (string) $response->getContent(), $match);
    $page = json_decode(html_entity_decode($match[1] ?? ''), true);

    return ['status' => $response->getStatusCode(), 'component' => $page['component'] ?? null, 'error' => $page['props']['status'] ?? null];
}

it('answers another tenant\'s real ID, a random ID and malformed IDs the same way', function (string $key) {
    $world = isolationWorld();
    $route = isolationRoute($key);
    $method = explode(' ', $key, 2)[0];
    $actor = $world['users']['alpha_member'];
    $foreign = collect(isolationTargets($world, $route))->first(fn (array $t): bool => in_array('alpha_member', $t['hidden'], true));
    $shapes = [];

    $variants = [
        'foreign' => $foreign['params'],
        'random' => array_map(fn (): string => (string) Str::uuid7(), $foreign['params']),
        'uppercase' => array_map(fn (string $v): string => strtoupper($v), $foreign['params']),
        'suffixed' => array_map(fn (string $v): string => $v.'0', $foreign['params']),
        'malformed' => array_map(fn (): string => 'not-a-uuid', $foreign['params']),
        'injection' => array_map(fn (): string => "' OR 1=1--", $foreign['params']),
    ];

    foreach ($variants as $name => $params) {
        $response = isolationCall($actor, $method, isolationUrl($route, $params));
        $shapes[$name] = isolationRefusalShape($response);
        app('auth')->forgetGuards();
    }

    foreach ($shapes as $name => $shape) {
        expect($shape)->toBe($shapes['random'], "{$name} differs from a random ID")
            ->and($shape['status'])->toBe(404);
    }
})->with('scoped routes');
