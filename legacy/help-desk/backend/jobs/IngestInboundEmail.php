<?php

namespace App\Jobs;

use App\Services\HelpDesk\InboundEmailIngestor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Ingests one arriving email off the queue (docs/features/help-desk.md — Phase 2, FR-2.4;
 * CLAUDE.md §11: do not run heavy work synchronously inside a controller).
 *
 * The webhook's job is to authenticate the request and get out of the way — a provider that
 * times out retries, and retrying is precisely how duplicate conversations are made. Parsing,
 * routing, threading and writing happen here.
 *
 * Retries are SAFE rather than merely tolerated: the ingestor is idempotent, so a job that
 * fails after writing and is retried finds its own message already stored and returns the same
 * conversation (§9, "background job fails and retries").
 */
class IngestInboundEmail implements ShouldQueue
{
    use Queueable;

    /**
     * Three attempts with a widening backoff.
     *
     * The failures worth retrying here are transient — a database blip, a lock timeout — and
     * they clear in seconds. The ones that are not transient (an address nobody owns, a payload
     * that is not a message) are already handled inside the ingestor rather than thrown, so
     * retrying is never a way of hoping a bad message becomes a good one.
     */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 30];

    /** @param  array<string, mixed>  $payload */
    public function __construct(private readonly array $payload)
    {
        $this->onQueue((string) config('help-desk.inbound.queue', 'default'));
    }

    public function handle(InboundEmailIngestor $ingestor): void
    {
        $ingestor->ingest($this->payload);
    }
}
