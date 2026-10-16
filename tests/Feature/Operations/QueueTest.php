<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\AlwaysFailingJob;
use Tests\Fixtures\QueueWorker;
use Tests\Fixtures\RecordQueueContextJob;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'queue.default' => 'database',
        // Redis is never required: point it at a closed port so any use would fail loudly.
        'database.redis.default.host' => '127.0.0.1',
        'database.redis.default.port' => 1,
        'database.redis.cache.host' => '127.0.0.1',
        'database.redis.cache.port' => 1,
    ]);
});

it('persists a job on the database queue and processes it without Redis', function () {
    dispatch(new RecordQueueContextJob);

    $this->assertDatabaseCount('jobs', 1);
    expect(Cache::get(RecordQueueContextJob::CACHE_KEY))->toBeNull();

    expect(QueueWorker::runOnce())->toBe(0);

    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
    expect(Cache::get(RecordQueueContextJob::CACHE_KEY))->toMatchArray([
        'job_name' => RecordQueueContextJob::class,
    ]);
});

it('carries the request_id into the job and adds the job_id to its log context', function () {
    Route::get('/_test/dispatch', function () {
        dispatch(new RecordQueueContextJob);

        return response()->noContent();
    });

    $this->withHeader('X-Request-Id', 'proxy-request-0001')
        ->get('/_test/dispatch')
        ->assertNoContent();

    $payload = json_decode(DB::table('jobs')->value('payload'), true);

    QueueWorker::runOnce();

    expect(Cache::get(RecordQueueContextJob::CACHE_KEY))->toBe([
        'request_id' => 'proxy-request-0001',
        'job_id' => $payload['uuid'],
        'job_name' => RecordQueueContextJob::class,
    ]);
});

it('does not leak one job context into the next job', function () {
    Route::get('/_test/dispatch', function () {
        dispatch(new RecordQueueContextJob);

        return response()->noContent();
    });
    $this->withHeader('X-Request-Id', 'proxy-request-0002')->get('/_test/dispatch');
    QueueWorker::runOnce();

    // Dispatched outside any request: no request_id may be inherited from the previous job.
    Context::flush();
    dispatch(new RecordQueueContextJob);
    QueueWorker::runOnce();

    expect(Cache::get(RecordQueueContextJob::CACHE_KEY)['request_id'])->toBeNull();
});

it('retries a failing job with backoff and then records it in failed_jobs', function () {
    dispatch(new AlwaysFailingJob);

    // Attempt 1 fails: released with the first backoff, not failed yet.
    QueueWorker::runOnce();
    $this->assertDatabaseCount('failed_jobs', 0);
    expect(DB::table('jobs')->value('attempts'))->toBe(1);

    // Not available again before the backoff elapses.
    QueueWorker::runOnce();
    expect(DB::table('jobs')->value('attempts'))->toBe(1);

    $this->travel(QueueWorker::BACKOFF[0] + 1)->seconds();
    QueueWorker::runOnce();
    $this->assertDatabaseCount('failed_jobs', 0);
    expect(DB::table('jobs')->value('attempts'))->toBe(2);

    $this->travel(QueueWorker::BACKOFF[1] + 1)->seconds();
    QueueWorker::runOnce();

    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 1);

    $failed = DB::table('failed_jobs')->first();
    expect($failed->connection)->toBe('database')
        ->and($failed->queue)->toBe('default')
        ->and($failed->uuid)->not->toBeEmpty()
        ->and($failed->exception)->toContain('Fixture job failure.');
});

it('keeps failed jobs visible to operators and retryable', function () {
    dispatch(new AlwaysFailingJob);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 1]);

    $uuid = DB::table('failed_jobs')->value('uuid');

    $this->artisan('queue:failed')->expectsOutputToContain($uuid)->assertSuccessful();
    $this->artisan('queue:retry', ['id' => [$uuid]])->assertSuccessful();

    $this->assertDatabaseCount('failed_jobs', 0);
    $this->assertDatabaseCount('jobs', 1);
});
