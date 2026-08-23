<?php

namespace App\Jobs;

use App\Models\HelpCenterRequest;
use App\Notifications\HelpCenterRequestAssigned;
use App\Services\HelpCenter\Inbound\InboundIngestor;
use App\Services\HelpCenter\Inbound\InboundRouter;
use App\Services\HelpCenter\Inbound\InboundTestRunner;
use App\Services\HelpCenter\Inbound\PostmarkPayload;
use App\Services\HelpCenter\Inbound\TicketConfirmer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ingest one inbound email off the queue (CLAUDE.md §11, docs/features/help-center.md P6).
 *
 * The webhook does nothing but authenticate and hand over: Postmark expects a fast 2xx and
 * retries anything slower or noisier, so parsing, routing, opening a Request and updating
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
        TicketConfirmer $confirmations,
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
         * without this the Request would be written with no tenant and be invisible to
         * every screen. The Inbox is what establishes which workspace this mail belongs to.
         */
        $workspace = $inbox->tenant;

        if ($workspace === null) {
            Log::error('help-center.inbound.orphan_inbox', ['inbox_id' => $inbox->id]);

            return;
        }

        $workspace->run(function () use ($ingestor, $tests, $confirmations, $inbox, $payload) {
            /*
             * A returning TEST probe completes its test and stops there (P7).
             *
             * Deliberately not also ingested: the probe is a message we sent to ourselves, and
             * turning it into a Request would put a fake customer at the top of the
             * Unassigned queue every time somebody checked their configuration.
             */
            $test = $tests->match($payload);

            if ($test !== null) {
                $tests->pass($test, $payload);

                return;
            }

            $request = $ingestor->ingest($inbox, $payload);

            if ($request !== null) {
                Log::info('help-center.inbound.ingested', [
                    'inbox_id' => $inbox->id,
                    'request_id' => $request->id,
                    'ticket' => $request->ticketNumber(),
                    'assignee_id' => $request->assignee_id,
                ]);

                $this->announceAssignment($request);
                $confirmations->confirm($request, $inbox, $payload);
            }
        });
    }

    /**
     * Tell the auto-assignee they have a Request (P26).
     *
     * AFTER `ingest()`, deliberately, and not inside it. The ingest runs in a transaction and
     * this notification is queued: dispatched from inside, a worker could pick it up before the
     * commit and read a Request that does not exist yet. Out here the row is committed.
     *
     * `wasRecentlyCreated` is what separates a NEW Request from a reply landing in an existing
     * one — the ingestor returns the Request either way, and only the first is an assignment
     * anybody needs telling about. A reply keeps whoever already holds the thread, so there is
     * nothing new to announce.
     *
     * Failure to mail must not fail the job. The Request is already stored, and `$tries = 3`
     * would re-run the whole ingest — which is idempotent, so it would not duplicate, but it
     * would log a stream of failures for something that is only a heads-up.
     */
    private function announceAssignment(HelpCenterRequest $request): void
    {
        if (! $request->wasRecentlyCreated || $request->assignee_id === null) {
            return;
        }

        $assignee = $request->assignee;

        if ($assignee === null) {
            return;
        }

        try {
            $assignee->notify(new HelpCenterRequestAssigned($request));
        } catch (Throwable $e) {
            Log::error('help-center.auto_assign.notify_failed', [
                'request_id' => $request->id,
                'assignee_id' => $request->assignee_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
