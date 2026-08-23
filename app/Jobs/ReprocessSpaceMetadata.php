<?php

namespace App\Jobs;

use App\Models\HelpCenterRequest;
use App\Models\HelpCenterSpace;
use App\Models\Workspace;
use App\Services\HelpCenter\Metadata\MetadataMapper;
use App\Services\HelpCenter\Metadata\TicketMetadata;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reprocess Existing Records (docs/features/help-center.md, P75 §14).
 *
 * "Changing a mapping should apply to new incoming ticket data going forward by default. Do not
 * automatically overwrite historical Customer or Company data. Optionally provide an
 * administrator action: Reprocess Existing Records."
 *
 * So this exists and is never called by anything but that button. It re-runs the Space's current
 * mappings over the metadata each Request was ingested with — which is why that metadata is
 * stored on the Request at all (P75 §8): there is nothing to re-run over otherwise, and going
 * back to the raw email would mean re-parsing bodies this job has no business reading.
 *
 * Queued, chunked, and deliberately NOT wrapped in one transaction: it can touch every Request
 * in a Space, and a single failing row should not undo the thousands before it.
 *
 * The learn-don't-overwrite rule still applies inside `MetadataMapper`, so this fills gaps and
 * links records that had none. It does not rewrite anything a person has typed — the
 * requirement's "do not automatically overwrite historical data" holds even here, where the
 * administrator asked for the run.
 */
class ReprocessSpaceMetadata implements ShouldQueue
{
    use Queueable;

    /** One attempt. A half-finished re-run repeated is worse than one that stopped and said so. */
    public int $tries = 1;

    /** Rows per chunk. Small enough that one failure loses little, large enough to be one query. */
    private const CHUNK = 200;

    public function __construct(
        private readonly int $spaceId,
        private readonly string $tenantId,
    ) {}

    public function handle(MetadataMapper $mapper): void
    {
        $workspace = Workspace::query()->withoutGlobalScopes()->find($this->tenantId);

        if ($workspace === null) {
            Log::error('help-center.reprocess.orphan_tenant', ['tenant_id' => $this->tenantId]);

            return;
        }

        // Inside the workspace, for the same reason ingest is: every model in the chain is
        // tenant-scoped and a job carries no tenancy context of its own.
        $workspace->run(function () use ($mapper) {
            $space = HelpCenterSpace::query()->find($this->spaceId);

            if ($space === null) {
                return;
            }

            $done = 0;

            HelpCenterRequest::query()
                ->where('help_center_space_id', $space->id)
                ->whereNotNull('inbound_metadata')
                ->orderBy('id')
                ->chunkById(self::CHUNK, function ($requests) use ($mapper, $space, &$done) {
                    foreach ($requests as $request) {
                        $mapper->apply($request, $space, TicketMetadata::make((array) $request->inbound_metadata));
                        $done++;
                    }
                });

            Log::info('help-center.reprocess.done', [
                'space_id' => $space->id,
                'requests' => $done,
            ]);
        });
    }
}
