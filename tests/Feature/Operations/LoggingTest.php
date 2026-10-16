<?php

use App\Support\Logging\RedactSensitiveData;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;

beforeEach(function () {
    config(['logging.channels.capture' => [
        'driver' => 'monolog',
        'handler' => TestHandler::class,
        'formatter' => JsonFormatter::class,
        'processors' => [PsrLogMessageProcessor::class],
    ]]);
});

/** @return list<LogRecord> */
function capturedRecords(string $channel = 'capture'): array
{
    /** @var TestHandler $handler */
    $handler = Log::channel($channel)->getLogger()->getHandlers()[0];

    return $handler->getRecords();
}

it('redacts nested secrets from context and Context extras on every channel', function () {
    Context::add('request_id', 'req-for-logging-test');
    Context::add('api_token', 'context-secret-value');

    Log::channel('capture')->warning('Login attempt for {email} with {password}', [
        'email' => 'someone@example.test',
        'password' => 'hunter2-plain',
        'request' => [
            'headers' => ['Authorization' => ['Bearer abc.def.ghi'], 'Cookie' => ['sn3_session=xyz'], 'Accept' => ['text/html']],
            'body' => ['password_confirmation' => 'hunter2-plain', 'name' => 'Omar'],
        ],
        'callback' => 'https://sn3.test/files/1?expires=99&signature=deadbeef',
    ]);

    [$record] = capturedRecords();
    $json = (string) $record->formatted;

    expect($json)
        ->not->toContain('hunter2-plain')
        ->not->toContain('abc.def.ghi')
        ->not->toContain('sn3_session=xyz')
        ->not->toContain('context-secret-value')
        ->not->toContain('deadbeef')
        ->toContain('req-for-logging-test')
        ->toContain('text/html')
        ->toContain('expires=99');

    // Placeholders are interpolated after redaction, so a secret cannot reach the message.
    expect($record->message)->toBe('Login attempt for someone@example.test with '.RedactSensitiveData::REPLACEMENT)
        ->and($record->extra['request_id'])->toBe('req-for-logging-test')
        ->and($record->extra['api_token'])->toBe(RedactSensitiveData::REPLACEMENT)
        ->and($record->context['request']['body']['name'])->toBe('Omar');

    expect(json_decode($json, true))->toHaveKeys(['message', 'context', 'level_name', 'channel', 'datetime', 'extra']);
});

it('emits one JSON object per line with the stderr channel configuration', function () {
    $config = require base_path('config/logging.php');

    expect($config['channels']['stderr']['formatter_with'])->toBe(['includeStacktraces' => true])
        ->and($config['channels']['daily']['days'])->toBe(30);
});
