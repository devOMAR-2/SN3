<?php

namespace App\Support;

use Throwable;

/**
 * Liveness markers for the long-running worker and scheduler processes.
 *
 * Each process touches its file while its loop is healthy; the container healthcheck in
 * docker-compose.yml only checks the file's age. Files live in storage/framework, which is
 * local to each container, so replicas never share or race on a heartbeat.
 */
final class Heartbeat
{
    public const QUEUE_WORKER = 'queue-worker';

    public const SCHEDULER = 'scheduler';

    public static function path(string $name): string
    {
        return storage_path("framework/{$name}.heartbeat");
    }

    public static function beat(string $name): void
    {
        try {
            touch(self::path($name));
        } catch (Throwable) {
            // An unwritable heartbeat must never stop the worker or scheduler; the
            // healthcheck reports the stale file instead.
        }
    }
}
