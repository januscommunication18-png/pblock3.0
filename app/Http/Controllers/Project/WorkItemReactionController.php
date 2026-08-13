<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\WorkItem;
use App\Models\WorkItemSubscriber;
use App\Models\WorkItemVote;
use App\Services\WorkItemReactions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The work item detail toolbar's vote and subscribe controls (POC html/work-items.html).
 *
 * Both are gated on **viewing** the item, not on editing it: voting on a proposal and asking
 * to hear about it are things a Viewer or a Commenter has every reason to do, and neither
 * changes the work item. `WorkItemPolicy@view` already refuses an item in a project the
 * caller cannot open, and 404s rather than 403s so a refusal never confirms it exists.
 *
 * Both endpoints TOGGLE, and both answer with the whole resulting state rather than "ok" —
 * the toolbar paints from the response instead of guessing what its click did, so two tabs
 * open on the same item cannot disagree about the count.
 */
class WorkItemReactionController extends Controller
{
    /**
     * POST /projects/{project}/work-items/{workItem}/vote
     *
     * Same side again takes the vote back; the other side switches it. One row per person
     * either way, which is what makes "I have already voted" a lookup rather than a sum.
     */
    public function vote(Request $request, Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guard($project, $workItem);

        $value = $request->validate([
            'value' => ['required', Rule::in(WorkItemVote::VALUES)],
        ])['value'];

        $existing = WorkItemVote::query()
            ->where('work_item_id', $workItem->id)
            ->where('user_id', Auth::id())
            ->first();

        if ($existing && $existing->value === $value) {
            $existing->delete();
        } elseif ($existing) {
            $existing->forceFill(['value' => $value])->save();
        } else {
            WorkItemVote::create([
                'work_item_id' => $workItem->id,
                'user_id' => Auth::id(),
                'value' => $value,
            ]);
        }

        return response()->json(['ok' => true] + $this->state($workItem));
    }

    /** POST /projects/{project}/work-items/{workItem}/subscribe — on, or back off. */
    public function subscribe(Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guard($project, $workItem);

        $existing = WorkItemSubscriber::query()
            ->where('work_item_id', $workItem->id)
            ->where('user_id', Auth::id())
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            WorkItemSubscriber::create(['work_item_id' => $workItem->id, 'user_id' => Auth::id()]);
        }

        return response()->json([
            'ok' => true,
            'message' => $existing ? 'Unsubscribed.' : 'Subscribed. You will hear about changes to this work item.',
        ] + $this->state($workItem));
    }

    /**
     * The vote counts and this viewer's own position — everything the toolbar paints from.
     *
     * The same service the row payload uses, so the count on a freshly loaded page and the
     * count after a click cannot come from two different definitions.
     *
     * @return array<string, mixed>
     */
    private function state(WorkItem $item): array
    {
        return app(WorkItemReactions::class)->one($item->id);
    }

    /** Reachable through this project's URL, and visible to this person. */
    private function guard(Project $project, WorkItem $workItem): void
    {
        abort_unless($workItem->project_id === $project->id, 404);
        abort_unless(Auth::user()->can('view', $workItem), 404);
    }
}
