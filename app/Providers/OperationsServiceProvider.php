<?php

namespace App\Providers;

use App\Support\Logging\ScrubbedContextLogProcessor;
use Illuminate\Contracts\Log\ContextLogProcessor;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Runtime operations (FND-007, ADR-015): log scrubbing and correlation IDs, queue job context.
 */
class OperationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Every channel Laravel creates runs this processor first: Context data, then redaction.
        $this->app->bind(ContextLogProcessor::class, ScrubbedContextLogProcessor::class);
    }

    public function boot(): void
    {
        // Laravel hydrates the dispatcher's Context (e.g. request_id) on JobProcessing and
        // flushes what the previous job left behind; this listener runs after that.
        Event::listen(function (JobProcessing $event): void {
            Context::add([
                'job_id' => $event->job->uuid(),
                'job_name' => $event->job->resolveName(),
                'job_attempt' => $event->job->attempts(),
            ]);
        });
    }
}
