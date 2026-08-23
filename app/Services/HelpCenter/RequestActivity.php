<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterRequest;
use App\Models\HelpCenterRequestActivity;
use Illuminate\Support\Facades\Auth;

/**
 * Writing a Request's history (docs/features/help-center.md, P36).
 *
 * ONE place that records, so a change made from the row, from the drawer, from an inbound email
 * or from a rule all leave the same row behind. The alternative — each caller writing its own —
 * is how a history ends up complete for the paths somebody remembered and silent for the rest,
 * which is worse than no history at all because it looks like one.
 *
 * Everything is stored as it will be DISPLAYED: an old status is its name, an old assignee is
 * their name, an old priority is its label. Ids go in `meta` for anything that later wants to
 * link. A history that renders `help_center_status_id: 31 → 33` is a history nobody can read.
 */
class RequestActivity
{
    public function __construct(private readonly TicketBroadcaster $broadcaster) {}

    /**
     * Record one event.
     *
     * `$actor` defaults to whoever is signed in, and stays NULL when nobody is — an inbound
     * email opening a Request, or a queue worker applying a rule. Null means "the system", and
     * the timeline says so rather than attributing it to whoever happened to be looking.
     */
    public function record(
        HelpCenterRequest $request,
        string $event,
        ?string $field = null,
        ?string $old = null,
        ?string $new = null,
        array $meta = [],
    ): ?HelpCenterRequestActivity {
        /*
         * A change that changed nothing is not history.
         *
         * Saving the same assignee, or picking the status a Request already holds, would
         * otherwise write "Status changed: Open → Open" — a row that costs a line in every tab
         * and answers nothing.
         */
        if ($field !== null && (string) $old === (string) $new) {
            return null;
        }

        $row = HelpCenterRequestActivity::create([
            'tenant_id' => $request->tenant_id,
            'help_center_request_id' => $request->id,
            'actor_id' => Auth::id(),
            'event' => $event,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'meta' => $meta === [] ? null : $meta,
        ]);

        /*
         * And tell whoever is watching the Space (P67).
         *
         * HERE rather than at each call site, for the reason this class exists at all: a screen
         * that updates itself is only useful if it updates for EVERY path that changes
         * something, and a broadcast written at each caller is a broadcast that exists for the
         * paths somebody remembered. A status changed from the row, from the drawer, from an
         * inbound rule or from a snooze waking all pass through here.
         *
         * SILENT — no toast. These are things agents do, and the agent who did it is already
         * looking at the result; a toast for every chip flipped on a busy queue is a screen
         * nobody can work in. The row still moves, which is what the requirement asks for.
         *
         * The early return above matters here too: a change that changed nothing writes no row
         * and now also sends no socket message, so saving the same assignee twice does not make
         * every open tab refetch.
         */
        $this->broadcaster->updated($request, $field);

        return $row;
    }

    /** The Request was opened — the first row of every timeline. */
    public function created(HelpCenterRequest $request, ?string $via = null): void
    {
        $this->record(
            $request,
            HelpCenterRequestActivity::EVENT_CREATED,
            meta: $via === null ? [] : ['via' => $via],
        );
    }
}
