<?php

namespace App\Providers;

use App\Mail\Transport\MetadataLogTransport;
use App\Support\Heartbeat;
use App\Support\Logging\ScrubbedContextLogProcessor;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Log\ContextLogProcessor;
use Illuminate\Log\LogManager;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Runtime operations (FND-007, ADR-015): log scrubbing and correlation IDs, queue job
 * context, worker/scheduler heartbeats and the safe `log` mail transport.
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

        Event::listen(fn (Looping $event) => Heartbeat::beat(Heartbeat::QUEUE_WORKER));

        Event::listen(function (CommandStarting|CommandFinished $event): void {
            if (($event instanceof CommandStarting && $event->command === 'schedule:work')
                || ($event instanceof CommandFinished && $event->command === 'schedule:run')) {
                Heartbeat::beat(Heartbeat::SCHEDULER);
            }
        });

        $this->callAfterResolving('mail.manager', function (MailManager $mail): void {
            $mail->extend('log', function (array $config): TransportInterface {
                $channel = $config['channel'] ?? config('mail.log_channel');
                $logger = $this->app->make(LogManager::class)->channel(is_string($channel) ? $channel : null);

                return ($config['include_body'] ?? false) === true
                    ? new LogTransport($logger)
                    : new MetadataLogTransport($logger);
            });
        });
    }
}
