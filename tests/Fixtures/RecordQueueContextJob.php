<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;

/** Records the log Context it ran with, so tests can assert correlation IDs. */
class RecordQueueContextJob implements ShouldQueue
{
    use Queueable;

    public const CACHE_KEY = 'tests.queue-context';

    public function handle(): void
    {
        Cache::forever(self::CACHE_KEY, [
            'request_id' => Context::get('request_id'),
            'job_id' => Context::get('job_id'),
            'job_name' => Context::get('job_name'),
        ]);
    }
}
