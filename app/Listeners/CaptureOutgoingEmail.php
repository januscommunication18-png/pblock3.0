<?php

namespace App\Listeners;

use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Persists a copy of every outbound message to email_logs so /emaillog can show it.
 * Active only when config('mail.capture_to_db', app()->environment('local')) is true (local/non-prod) — spec D-A5.
 *
 * Registration is by Laravel's listener auto-discovery (it lives in app/Listeners and
 * type-hints the event). Do NOT also `Event::listen` it from a service provider: it would
 * then be subscribed twice and every message would be logged — and shown in /emaillog —
 * twice, which reads as the application sending duplicate mail.
 */
class CaptureOutgoingEmail
{
    public function handle(MessageSending $event): void
    {
        if (! config('mail.capture_to_db', app()->environment('local'))) {
            return;
        }

        $message = $event->message; // Symfony\Component\Mime\Email

        $to = $this->addresses($message->getTo());
        $from = $this->addresses($message->getFrom());

        EmailLog::create([
            'to' => $to,
            'from' => $from,
            'subject' => $message->getSubject(),
            'html_body' => $message->getHtmlBody(),
            'text_body' => $message->getTextBody(),
            'mailer' => config('mail.default'),
            'created_at' => now(),
        ]);
    }

    /** @param array<int, Address> $addresses */
    private function addresses(array $addresses): string
    {
        return collect($addresses)
            ->map(fn ($a) => trim($a->getName().' <'.$a->getAddress().'>'))
            ->implode(', ');
    }
}
