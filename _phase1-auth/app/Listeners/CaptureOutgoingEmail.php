<?php

namespace App\Listeners;

use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSending;

/**
 * Persists a copy of every outbound message to email_logs so /emaillog can show it.
 * Active only when config('mail.capture_to_db') is true (local/non-prod) — spec D-A5.
 */
class CaptureOutgoingEmail
{
    public function handle(MessageSending $event): void
    {
        if (! config('mail.capture_to_db')) {
            return;
        }

        $message = $event->message; // Symfony\Component\Mime\Email

        $to   = $this->addresses($message->getTo());
        $from = $this->addresses($message->getFrom());

        EmailLog::create([
            'to'         => $to,
            'from'       => $from,
            'subject'    => $message->getSubject(),
            'html_body'  => $message->getHtmlBody(),
            'text_body'  => $message->getTextBody(),
            'mailer'     => config('mail.default'),
            'created_at' => now(),
        ]);
    }

    /** @param array<int, \Symfony\Component\Mime\Address> $addresses */
    private function addresses(array $addresses): string
    {
        return collect($addresses)
            ->map(fn ($a) => trim($a->getName().' <'.$a->getAddress().'>'))
            ->implode(', ');
    }
}
