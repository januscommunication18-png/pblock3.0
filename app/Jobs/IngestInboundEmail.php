<?php

namespace App\Jobs;

use App\Services\HelpCenter\Inbound\InboundIngestor;
use App\Services\HelpCenter\Inbound\InboundRouter;
use App\Services\HelpCenter\Inbound\InboundTestRunner;
use App\Services\HelpCenter\Inbound\PostmarkPayload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Ingest one inbound email off the queue (CLAUDE.md §11, docs/features/help-center.md P6).
 *
 * The webhook does nothing but authenticate and hand over: Postmark expects a fast 2xx and
 * retries anything slower or noisier, so parsing, routing, opening a conversation and updating
 * address statuses all belong behind the queue rather than inside the request.
 *
 * The whole payload is carried, not an id, because there is nothing to point at yet — this job
 * IS what turns the payload into rows.
 */
class IngestInboundEmail implements ShouldQueue
{
    use Queueable;

    /** Postmark retries on its own schedule; three attempts here is for transient DB trouble. */
    public int $tries = 3;

    public int $backoff = 30;

    /** @param  array<string, mixed>  $payload */
    public function __construct(private readonly array $payload) {}

    public function handle(
        InboundRouter $router,
        InboundIngestor $ingestor,
        InboundTestRunner $tests,
    ): void {
        $payload = PostmarkPayload::from($this->payload);

        $inbox = $router->resolve($payload->routableAddresses());

        if ($inbox === null) {
            /*
             * Addressed to nothing we own.
             *
             * Logged and dropped rather than retried: a message for a deleted Inbox, or one
             * whose token was mistyped in a forwarding rule, will never resolve however many
             * times it is tried. The recipients are recorded so the misconfiguration is
             * findable; the body is not, because it is somebody's mail.
             */
            Log::warning('help-center.inbound.unroutable', [
                'recipients' => $payload->routableAddresses(),
                'from' => $payload->fromEmail(),
            ]);

            return;
        }

        /*
         * Inside the Inbox's own workspace.
         *
         * A webhook has no tenancy context, and every model in the chain is tenant-scoped —
         * without this the conversation would be written with no tenant and be invisible to
         * every screen. The Inbox is what establishes which workspace this mail belongs to.
         */
        $workspace = $inbox->tenant;

        if ($workspace === null) {
            Log::error('help-center.inbound.orphan_inbox', ['inbox_id' => $inbox->id]);

            return;
        }

        $workspace->run(function () use ($ingestor, $tests, $inbox, $payload) {
            /*
             * A returning TEST probe completes its test and stops there (P7).
             *
             * Deliberately not also ingested: the probe is a message we sent to ourselves, and
             * turning it into a conversation would put a fake customer at the top of the
             * Unassigned queue every time somebody checked their configuration.
             */
            $test = $tests->match($payload);

            if ($test !== null) {
                $tests->pass($test, $payload);

                return;
            }

            $conversation = $ingestor->ingest($inbox, $payload);

            if ($conversation !== null) {
                Log::info('help-center.inbound.ingested', [
                    'inbox_id' => $inbox->id,
                    'conversation_id' => $conversation->id,
                ]);
            }
        });
    }
}
