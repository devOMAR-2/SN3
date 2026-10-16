<?php

use App\Http\Middleware\AssignRequestId;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_test/request-id', fn () => response()->json([
        'context' => Context::get('request_id'),
        'attribute' => request()->attributes->get('request_id'),
    ]));
});

const GENERATED_REQUEST_ID = '/\Areq_[0-9A-HJKMNP-TV-Z]{26}\z/';

it('accepts a well-formed incoming X-Request-Id and uses it everywhere', function (string $incoming) {
    $this->withHeader('X-Request-Id', $incoming)
        ->getJson('/_test/request-id')
        ->assertOk()
        ->assertHeader('X-Request-Id', $incoming)
        ->assertExactJson(['context' => $incoming, 'attribute' => $incoming]);
})->with([
    'uuid' => '0b9e8d7c-6f5a-4b3c-9d2e-1f0a9b8c7d6e',
    'prefixed ulid' => 'req_01JEXAMPLE0000000000000000',
    'dotted' => 'edge.proxy-42_a',
]);

it('generates a request id when none is sent', function () {
    $response = $this->getJson('/_test/request-id')->assertOk();

    $id = $response->headers->get('X-Request-Id');
    expect($id)->toMatch(GENERATED_REQUEST_ID)
        ->and($response->json('context'))->toBe($id)
        ->and($response->json('attribute'))->toBe($id);
});

it('replaces malformed or injected request ids', function (string $incoming) {
    $response = $this->withHeader('X-Request-Id', $incoming)->getJson('/_test/request-id')->assertOk();

    expect($response->headers->get('X-Request-Id'))->toMatch(GENERATED_REQUEST_ID)
        ->and($response->json('context'))->not->toBe($incoming);
})->with([
    'too short' => 'abc1234',
    'too long' => str_repeat('a', 65),
    'trailing newline' => "valid-request-id\n",
    'log line injection' => "abc12345\r\n{\"level\":\"emergency\"}",
    'markup' => '<script>alert(1)</script>',
    'spaces' => 'has some spaces',
    'leading separator' => '-starts-with-dash',
    'non-ascii' => 'طلب-١٢٣٤٥٦٧٨',
]);

it('sets the header on probes and unmatched routes too', function (string $uri, int $status) {
    $this->get($uri)
        ->assertStatus($status)
        ->assertHeader('X-Request-Id');
})->with([
    ['/health/live', 200],
    ['/no-such-page', 404],
]);

it('rejects ids the pattern does not allow', function () {
    expect(preg_match(AssignRequestId::PATTERN, "abcdefgh\n"))->toBe(0)
        ->and(preg_match(AssignRequestId::PATTERN, 'abcdefgh'))->toBe(1);
});
