<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/IsolationWorld.php';

// CORE-011 (AGENTS.md §6 rule 1 "guessing UUIDs cannot bypass scoping", §8 "guessed identifiers"): for
// every scoped route, another tenant's real resource (and, for a Team Admin, a private project of their own
// team), a random UUID and malformed identifiers get the same status, headers and body, so a response never
// tells whether something exists.

uses(RefreshDatabase::class);

dataset('scoped routes', fn () => array_combine(IsolationRoutes::SCOPED, array_map(fn (string $key): array => [$key], IsolationRoutes::SCOPED)));

/**
 * A refusal with what may differ between two requests removed: request IDs, dates, cookies and the page's
 * own URL. Everything else (status, every header, the whole body) must match.
 */
function isolationRefusalShape(TestResponse $response): array
{
    $headers = collect($response->headers->all())
        ->except(['date', 'set-cookie', 'x-request-id', 'content-length', 'x-ratelimit-remaining', 'x-ratelimit-reset', 'retry-after'])
        ->sortKeys()->all();
    $content = (string) $response->getContent();
    $json = json_decode($content, true);

    if (is_array($json)) {
        unset($json['error']['request_id'], $json['meta']['request_id']);

        return ['status' => $response->getStatusCode(), 'headers' => $headers, 'body' => $json];
    }

    preg_match('/data-page="([^"]*)"/', $content, $match);
    $page = json_decode(html_entity_decode($match[1] ?? ''), true);
    if (is_array($page)) {
        unset($page['url']);
    }

    return ['status' => $response->getStatusCode(), 'headers' => $headers, 'page' => $page];
}

it('answers another tenant\'s real ID, a random ID and malformed IDs the same way', function (string $key) {
    $world = isolationWorld();
    $route = isolationRoute($key);
    $method = explode(' ', $key, 2)[0];
    $targets = isolationTargets($world, $route);

    // Another team's resource, and (DECIDED-003) a private project of the caller's own team.
    $probes = [
        'alpha_member' => collect($targets)->first(fn (array $t): bool => in_array('alpha_member', $t['hidden'], true)),
        'alpha_admin' => collect($targets)->first(fn (array $t): bool => str_contains($t['label'], 'alpha_private') && in_array('alpha_admin', $t['hidden'], true)),
    ];

    foreach (array_filter($probes) as $userKey => $foreign) {
        $actor = $world['users'][$userKey];
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
            expect($shape)->toBe($shapes['random'], "{$userKey}: {$name} differs from a random ID")
                ->and($shape['status'])->toBe(404);
        }
    }
})->with('scoped routes');
