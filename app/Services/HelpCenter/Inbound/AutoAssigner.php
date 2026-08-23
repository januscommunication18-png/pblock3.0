<?php

namespace App\Services\HelpCenter\Inbound;

use App\Models\HelpCenterRequest;
use App\Models\HelpCenterSpaceMember;
use App\Models\HelpCenterStatus;
use Illuminate\Support\Facades\Log;

/**
 * Who a new Request opens against (docs/features/help-center.md, P26).
 *
 * A workflow status can carry **Default assignees** — the wizard's step 4 and Settings → Workflow
 * both collect them. Until now the field was stored and displayed and did nothing: every inbound
 * email landed Unassigned regardless, and somebody had to notice it and pick it up. This is the
 * half that was missing.
 *
 * It runs ONLY when a Request is opened, never on a reply. A customer answering an existing
 * thread must not move it to somebody else — the person already holding it is holding it.
 */
class AutoAssigner
{
    /**
     * Which of a status's default assignees should take this one, or null for none.
     *
     * Two rules, and the second is the reason this is not simply `[0]`:
     *
     *   1. They must still be a MEMBER of the Space. The pickers only ever offered members, but
     *      a list stored months ago outlives the person leaving — and assigning to somebody who
     *      cannot open the Space is assigning to nobody, silently.
     *
     *   2. Of those, the one carrying the FEWEST open Requests in this Space, ties broken by
     *      their order in the list. A list of five people where the first one always wins is a
     *      list that means the same as a list of one; balancing is the least this field can do
     *      to be worth naming five people in.
     */
    public function pick(HelpCenterStatus $status, int $spaceId): ?int
    {
        $candidates = array_values(array_unique(array_map(
            'intval',
            (array) ($status->default_assignees ?: []),
        )));

        if ($candidates === []) {
            return null;
        }

        $members = HelpCenterSpaceMember::query()
            ->withoutGlobalScopes()
            ->where('help_center_space_id', $spaceId)
            ->whereIn('user_id', $candidates)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Order is the status's, not the database's — rule 2's tie-break depends on it.
        $eligible = array_values(array_filter($candidates, fn (int $id) => in_array($id, $members, true)));

        if ($eligible === []) {
            /*
             * Named, but none of them are on the Space any more.
             *
             * Unassigned is the honest outcome — it is a queue somebody watches — and the log
             * line is what turns "why is nothing being assigned?" into a five-second answer.
             */
            Log::warning('help-center.auto_assign.no_eligible_assignee', [
                'status_id' => $status->id,
                'space_id' => $spaceId,
                'named' => $candidates,
            ]);

            return null;
        }

        if (count($eligible) === 1) {
            return $eligible[0];
        }

        // One grouped query for the whole shortlist rather than a count each.
        $load = HelpCenterRequest::query()
            ->withoutGlobalScopes()
            ->where('help_center_space_id', $spaceId)
            ->whereIn('assignee_id', $eligible)
            ->whereNull('closed_at')
            ->where('is_spam', false)
            ->selectRaw('assignee_id, COUNT(*) AS open_count')
            ->groupBy('assignee_id')
            ->pluck('open_count', 'assignee_id');

        $best = null;
        $bestLoad = null;

        foreach ($eligible as $id) {
            $count = (int) ($load[$id] ?? 0);

            // Strictly less than, so an earlier name keeps a tie — that is the list's order
            // doing the tie-breaking rather than whatever order the rows came back in.
            if ($bestLoad === null || $count < $bestLoad) {
                $best = $id;
                $bestLoad = $count;
            }
        }

        return $best;
    }
}
