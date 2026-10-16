<?php

namespace App\Mail\Transport;

use Illuminate\Mail\Transport\LogTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\RawMessage;

/**
 * The `log` mail transport outside local development.
 *
 * Laravel's LogTransport writes the whole message, and SN3 mail carries sign-in,
 * verification, reset and invitation links that must never reach logs (API_SPEC.md §13).
 * This transport records only that a message was not delivered, so an operator without
 * SMTP sees a clear warning instead of a silent success (ARCHITECTURE.md §11.1).
 */
class MetadataLogTransport extends LogTransport
{
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $sent = new SentMessage($message, $envelope ?? Envelope::create($message));

        $this->logger->warning('Mail was not delivered: the log mailer is active. Configure SMTP to deliver mail.', [
            'mail_message_id' => $sent->getMessageId(),
            'mail_recipient_count' => count($sent->getEnvelope()->getRecipients()),
        ]);

        return $sent;
    }
}
