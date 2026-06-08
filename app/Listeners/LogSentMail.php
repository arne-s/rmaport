<?php

namespace App\Listeners;

use App\Enums\MailLogStatus;
use App\Models\MailLog;
use Illuminate\Mail\Events\MessageSent;

class LogSentMail
{
    public function __construct()
    {
        //
    }

    public function handle(MessageSent $event): void
    {
        $message = $event->message;
        $messageId = $message->getHeaders()->getHeaderBody(MailLog::EMAIL_HEADER_MESSAGE_ID);

        if (empty($messageId)) return;

        $mailLog = MailLog::whereMessageId($messageId)->first();

        if ($mailLog && $mailLog->status === MailLogStatus::Sending) {
            $mailLog->update(['status' => MailLogStatus::Sent]);
        }
    }
}
