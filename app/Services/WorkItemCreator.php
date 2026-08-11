<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectItemState;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemTransition;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates a work item and allocates its unique ID number atomically (spec §9, WI-006).
 *
 * The ID (1, 2, 3 …) must be unique and gap-free across the *workspace*, so the counter lives
 * on `tenants.work_item_sequence` and is read under `lockForUpdate()` inside the same
 * transaction that inserts the row. Two concurrent creates therefore serialize on the tenant
 * row instead of racing to `MAX(sequence_no) + 1`; if anything later in the transaction
 * fails, the counter rolls back with it. `UNIQUE(tenant_id, sequence_no)` backs the whole
 * thing at the database level.
 *
 * The counter is read through the query builder rather than the Workspace model: stancl's
 * VirtualColumn rewrites the `data` overflow column on every save, and an ID allocation has
 * no business touching the rest of the workspace record.
 */
class WorkItemCreator
{
    public function __construct(
        private readonly WorkItemActivityRecorder $activity,
        private readonly WorkItemAssignmentNotifier $assignments,
    ) {}

    /**
     * @param  array{title:string, description:?string, state_id:?int, priority:?string, start_date:?string, due_date:?string, parent_id:?int, cycle_id?:?int, assignee_ids?:array<int,int>, label_ids?:array<int,int>}  $data
     */
    public function create(User $creator, Project $project, array $data): WorkItem
    {
        return DB::transaction(function () use ($creator, $project, $data) {
            $sequence = $this->nextNumber($project);

            /** @var WorkItem $item */
            $item = WorkItem::create([
                'project_id' => $project->id,
                'sequence_no' => $sequence,
                'identifier' => (string) $sequence,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'state_id' => $data['state_id'] ?? null,
                'priority' => $data['priority'] ?? WorkItem::PRIORITY_NONE,
                'start_date' => $data['start_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'parent_id' => $data['parent_id'] ?? null,
                // Cycles §7.3: an item can be created already planned into a cycle, from the
                // cycle detail page's "create new work item" action.
                'cycle_id' => $data['cycle_id'] ?? null,
                'cycle_assigned_by' => ! empty($data['cycle_id']) ? $creator->id : null,
                'cycle_assigned_at' => ! empty($data['cycle_id']) ? now() : null,
                'created_by' => $creator->id,
            ]);

            if (! empty($data['assignee_ids'])) {
                $assigneeIds = array_values(array_unique($data['assignee_ids']));
                $item->assignees()->sync($assigneeIds);

                // Created already assigned to someone: that person is told too, on the same
                // terms as a later assignment (§4.3).
                foreach (User::whereIn('id', $assigneeIds)->get() as $assignee) {
                    $this->assignments->assigned($item, $assignee, $creator);
                }
            }
            if (! empty($data['label_ids'])) {
                $item->labels()->sync(array_values(array_unique($data['label_ids'])));
            }

            // Inside the transaction: a work item must never exist without its creation
            // entry, and activity cannot be reconstructed after the fact (§6).
            $this->activity->created($item, $creator);

            // §10.5: capture the state it started in, so time spent in that first state can
            // be worked out later. Without this the Transition tab begins mid-story.
            $state = $item->state_id ? ProjectItemState::find($item->state_id) : null;
            WorkItemTransition::create([
                'project_id' => $item->project_id,
                'work_item_id' => $item->id,
                'from_state_id' => null,
                'to_state_id' => $state?->id,
                'from_state_name' => null,
                'to_state_name' => $state?->name,
                'actor_id' => $creator->id,
                'transitioned_at' => now(),
            ]);

            return $item;
        });
    }

    /**
     * Claim the next ID number for the project's workspace. Must run inside a transaction:
     * the row lock is what makes two simultaneous creates take different numbers.
     */
    private function nextNumber(Project $project): int
    {
        $tenants = (new Workspace)->getTable();

        $current = DB::table($tenants)
            ->where('id', $project->tenant_id)
            ->lockForUpdate()
            ->value('work_item_sequence');

        if ($current === null) {
            throw new RuntimeException("Cannot allocate a work item ID: workspace [{$project->tenant_id}] is missing.");
        }

        $next = (int) $current + 1;
        DB::table($tenants)->where('id', $project->tenant_id)->update(['work_item_sequence' => $next]);

        return $next;
    }
}
