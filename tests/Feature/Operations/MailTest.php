<?php

use App\Mail\Transport\MetadataLogTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Tests\Fixtures\QueuedMailNotification;
use Tests\Fixtures\QueueWorker;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'queue.default' => 'database',
        // An SMTP server that refuses connections immediately.
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 1,
        'mail.mailers.log.channel' => 'mail_capture',
        'logging.channels.mail_capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
    ]);

    Route::get('/_test/notify', function () {
        Notification::route('mail', 'recipient@example.test')->notify(new QueuedMailNotification);

        return response()->noContent();
    });
});

function capturedMailLog(): string
{
    /** @var TestHandler $handler */
    $handler = Log::channel('mail_capture')->getLogger()->getHandlers()[0];

    return collect($handler->getRecords())
        ->map(fn (LogRecord $record) => $record->message.' '.json_encode($record->context).' '.json_encode($record->extra))
        ->implode("\n");
}

it('queues mail so an unreachable SMTP server never fails the web request', function () {
    config(['mail.default' => 'smtp']);

    $this->get('/_test/notify')->assertNoContent();

    $this->assertDatabaseCount('jobs', 1);
    $this->assertDatabaseCount('failed_jobs', 0);
});

it('retries undeliverable SMTP mail and then records it in failed_jobs', function () {
    config(['mail.default' => 'smtp']);
    $this->get('/_test/notify')->assertNoContent();

    QueueWorker::runOnce();
    $this->assertDatabaseCount('failed_jobs', 0);

    $this->travel(QueueWorker::BACKOFF[0] + 1)->seconds();
    QueueWorker::runOnce();
    $this->travel(QueueWorker::BACKOFF[1] + 1)->seconds();
    QueueWorker::runOnce();

    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 1);
    expect(DB::table('failed_jobs')->value('exception'))->toContain('TransportException');
});

it('falls back from SMTP to the log with the failover mailer, without logging the message', function () {
    config(['mail.default' => 'failover', 'mail.mailers.log.include_body' => false]);
    $this->get('/_test/notify')->assertNoContent();

    QueueWorker::runOnce();

    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);

    $log = capturedMailLog();
    expect($log)
        ->toContain('Mail was not delivered')
        ->toContain('mail_message_id')
        ->not->toContain('recipient@example.test')
        ->not->toContain('Fixture body line')
        ->not->toContain(QueuedMailNotification::SECRET);
});

it('uses the metadata-only log transport unless message bodies are explicitly enabled', function (bool $includeBody, string $transport) {
    config(['mail.default' => 'log', 'mail.mailers.log.include_body' => $includeBody]);

    expect(Mail::mailer('log')->getSymfonyTransport())->toBeInstanceOf($transport);
})->with([
    'metadata only (default outside local)' => [false, MetadataLogTransport::class],
    'full message (local development)' => [true, LogTransport::class],
]);

it('still redacts signed-URL parameters when full message bodies are logged', function () {
    config(['mail.default' => 'log', 'mail.mailers.log.include_body' => true]);

    Mail::raw('Open https://sn3.test/reset?token=plain-secret-token&lang=ar', function (Message $message) {
        $message->to('dev@example.test')->subject('Local only');
    });

    expect(capturedMailLog())
        ->toContain('Local only')
        ->toContain('lang=ar')
        ->not->toContain('plain-secret-token');
});

it('defaults to full bodies only when APP_ENV is local', function () {
    $config = require base_path('config/mail.php');

    // phpunit.xml runs with APP_ENV=testing and no MAIL_LOG_BODY.
    expect($config['mailers']['log']['include_body'])->toBeFalse();
});
