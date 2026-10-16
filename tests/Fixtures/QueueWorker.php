<?php

namespace Tests\Fixtures;

use Illuminate\Support\Facades\Artisan;

/** Runs the queue worker in-process with the retry options of the Compose `worker` service. */
final class QueueWorker
{
    public const TRIES = 3;

    /** Backoff in seconds before attempts 2 and 3 (docker-compose.yml `--backoff`). */
    public const BACKOFF = [10, 60];

    public static function runOnce(): int
    {
        return Artisan::call('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--sleep' => 0,
            '--tries' => self::TRIES,
            '--backoff' => '10,60,300',
            '--timeout' => 60,
        ]);
    }
}
