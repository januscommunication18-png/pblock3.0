<?php

namespace App\Jobs;

use App\Services\HelpDesk\EmailDeliveryRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Records one outbound delivery event off the queue (FR-2.9; CLAUDE.md §11).
 *
 * Same shape as IngestInboundEmail, and for the same reason: the webhook authenticates and gets
 * out of the way, because a provider that times out retries. Retries are safe — the recorder
 * dedupes on the provider's event id, so a repeated bounce raises no second alarm.
 */
class RecordEmailDeliveryEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 30];

    /** @param  array<string, mixed>  $payload */
    public function __construct(private readonly array $payload)
    {
        $this->onQueue((string) config('help-desk.inbound.queue', 'default'));
    }

    public function handle(EmailDeliveryRecorder $recorder): void
    {
        $recorder->record($this->payload);
    }
}
