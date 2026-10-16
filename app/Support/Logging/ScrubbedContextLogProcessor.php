<?php

namespace App\Support\Logging;

use Illuminate\Log\Context\ContextLogProcessor;
use Monolog\LogRecord;

/**
 * Laravel pushes the bound ContextLogProcessor onto every log channel it creates
 * (including stacks), as the first processor to run. Binding this subclass therefore
 * adds `Context` data (request_id, job_id, ...) and then redacts the whole record on
 * every channel, before placeholder interpolation or formatting can copy a secret
 * into the message.
 */
class ScrubbedContextLogProcessor extends ContextLogProcessor
{
    public function __construct(private readonly RedactSensitiveData $redactor) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return ($this->redactor)(parent::__invoke($record));
    }
}
