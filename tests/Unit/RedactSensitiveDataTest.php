<?php

use App\Support\Logging\RedactSensitiveData;
use Monolog\Level;
use Monolog\LogRecord;

function redact(string $message, array $context = [], array $extra = []): LogRecord
{
    return (new RedactSensitiveData)(new LogRecord(new DateTimeImmutable, 'test', Level::Info, $message, $context, $extra));
}

it('recognises sensitive keys regardless of case and separators', function (string $key) {
    expect(RedactSensitiveData::isSensitiveKey($key))->toBeTrue();
})->with([
    'password', 'password_confirmation', 'current_password', 'PASSWORD', 'passwd',
    'token', '_token', 'remember_token', 'access_token', 'installation_token', 'X-CSRF-TOKEN',
    'secret', 'client_secret', 'webhook_secret',
    'authorization', 'Authorization', 'proxy-authorization',
    'cookie', 'Set-Cookie',
    'api_key', 'apiKey', 'X-Api-Key',
    'signature', 'X-Hub-Signature-256',
    'private_key', 'credentials',
]);

it('leaves ordinary keys alone', function (string|int $key) {
    expect(RedactSensitiveData::isSensitiveKey($key))->toBeFalse();
})->with(['name', 'email', 'request_id', 'job_id', 'status', 'key', 'cache_key', 'exception', 0, 1]);

it('scrubs nested context and extra recursively', function () {
    $record = redact('m', [
        'user' => ['id' => 7, 'credentials' => ['password' => 'p']],
        'list' => [['token' => 't1'], ['token' => 't2', 'ok' => 'yes']],
        'Authorization' => 'Bearer x',
    ], [
        'request_id' => 'req_1',
        'nested' => ['deeper' => ['client_secret' => 's']],
    ]);

    expect($record->context)->toBe([
        'user' => ['id' => 7, 'credentials' => RedactSensitiveData::REPLACEMENT],
        'list' => [['token' => RedactSensitiveData::REPLACEMENT], ['token' => RedactSensitiveData::REPLACEMENT, 'ok' => 'yes']],
        'Authorization' => RedactSensitiveData::REPLACEMENT,
    ])->and($record->extra)->toBe([
        'request_id' => 'req_1',
        'nested' => ['deeper' => ['client_secret' => RedactSensitiveData::REPLACEMENT]],
    ]);
});

it('scrubs sensitive query parameters in the message and in string values', function () {
    $record = redact(
        'GET /files/9?expires=5&signature=abc123&lang=en',
        ['url' => 'https://x.test/cb?code=1&access_token=zzz#frag', 'plain' => 'a=b'],
    );

    expect($record->message)->toBe('GET /files/9?expires=5&signature=[REDACTED]&lang=en')
        ->and($record->context['url'])->toBe('https://x.test/cb?code=1&access_token=[REDACTED]#frag')
        ->and($record->context['plain'])->toBe('a=b');
});

it('bounds recursion depth', function () {
    $deep = ['v' => 'leaf'];
    for ($i = 0; $i < 20; $i++) {
        $deep = ['n' => $deep];
    }

    $record = redact('m', $deep);

    expect(json_encode($record->context))->not->toContain('leaf');
});

it('leaves non-array, non-string values untouched', function () {
    $exception = new RuntimeException('boom');

    $record = redact('m', ['count' => 3, 'flag' => true, 'exception' => $exception, 'nothing' => null]);

    expect($record->context)->toBe(['count' => 3, 'flag' => true, 'exception' => $exception, 'nothing' => null]);
});
