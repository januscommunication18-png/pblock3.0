<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\Epic;
use App\Models\EstimateValue;
use App\Models\Mention;
use App\Models\Module;
use App\Models\ProjectItemLabel;
use App\Models\ProjectItemState;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Models\WorkItemTransition;
use Illuminate\Support\Facades\DB;

/**
 * Applies work-item edits and records what changed (spec §4.2 / §6).
 *
 * Every mutation funnels through here so the audit feed cannot drift from reality: the diff
 * and its activity entries are written in one transaction, so a property can never change
 * without a matching history row. This is what finally fills §6's Activity, Transition
 * (`field = 'state'`) and History (rows carrying before/after) feeds.
 */
class WorkItemUpdater
{
    /** Scalar columns, in the order the feed reads best. */
    private const FIELDS = ['title', 'description', 'state_id', 'priority', 'start_date', 'due_date', 'parent_id', 'cycle_id', 'epic_id', 'estimate_value_id'];

    public function __construct(
        private readonly WorkItemActivityRecorder $activity,
        private readonly WorkItemAssignmentNotifier $assignments,
        private readonly WorkItemStatusNotifier $statusNotifier,
        private readonly MentionSync $mentions,
        private readonly MentionNotifier $mentionNotifier,
        private readonly InboxNotifier $inbox,
        private readonly RichTextSanitizer $richText,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated attributes; only keys present are touched.
     */
    public function update(WorkItem $item, User $actor, array $data): WorkItem
    {
        return DB::transaction(function () use ($item, $actor, $data) {
            foreach (self::FIELDS as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $old = $this->scalar($item, $field);
                $new = $this->normalize($field, $data[$field]);
                if ($old === $new) {
                    continue;
                }

                $item->{$field} = $data[$field];
                $this->log($item, $actor, $field, $old, $new);

                // Cycles §16: who put this item in its current cycle, and when. The full move
                // history is the activity row above; these two columns are just the latest.
                if ($field === 'cycle_id') {
                    $item->cycle_assigned_by = $new === null ? null : $actor->id;
                    $item->cycle_assigned_at = $new === null ? null : now();
                }

                // §10.6/§13.2: a state change — and only a state change — also writes a
                // transition, in the same transaction as the change itself.
                if ($field === 'state_id') {
                    $this->recordTransition($item, $actor, $old, $new);
                    // …and tells whoever subscribed. Registered inside the transaction but
                    // sent after it commits, so a rolled-back edit sends nothing.
                    $this->statusNotifier->statusChanged($item, $actor, $old ? (int) $old : null, $new ? (int) $new : null);
                }
            }

            $item->save();

            // §11: a diff, not a resend — only people newly named in the new text are told.
            if (array_key_exists('description', $data)) {
                $item->loadMissing('project');
                if ($item->project) {
                    $this->mentionNotifier->mentioned(
                        $this->mentions->sync(Mention::SOURCE_WORK_ITEM, $item->id, $item->description, $item->project, $item, $actor),
                        $item, $actor, Mention::SOURCE_WORK_ITEM, $item->description,
                    );
                }
            }

            if (array_key_exists('assignee_ids', $data)) {
                $this->syncRelation($item, $actor, 'assignees', $data['assignee_ids']);
            }
            if (array_key_exists('label_ids', $data)) {
                $this->syncRelation($item, $actor, 'labels', $data['label_ids']);
            }
            // Modules §9.3: many-to-many, so it syncs like labels rather than being a column
            // like `cycle_id`. §17 wants the add/remove in history, which syncRelation gives.
            if (array_key_exists('module_ids', $data)) {
                $this->syncRelation($item, $actor, 'modules', $data['module_ids']);
            }

            return $item->fresh(['state', 'assignees', 'labels', 'parent', 'cycle', 'epic', 'estimateValue', 'modules', 'creator']);
        });
    }

    /** Archive / restore (§4.4). Archived items leave the default list but keep their data. */
    public function setArchived(WorkItem $item, User $actor, bool $archived): WorkItem
    {
        if ($item->isArchived() === $archived) {
            return $item;
        }

        return DB::transaction(function () use ($item, $actor, $archived) {
            $item->archived_at = $archived ? now() : null;
            $item->save();

            $this->activity->record($item, $actor, $archived ? 'archived' : 'restored');

            return $item->fresh(['state', 'assignees', 'labels', 'creator']);
        });
    }

    /** The value we compare and store in the feed — always a string or null. */
    private function scalar(WorkItem $item, string $field): ?string
    {
        $value = $item->{$field};

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function normalize(string $field, $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array($field, ['start_date', 'due_date'], true)
            ? substr((string) $value, 0, 10)
            : (string) $value;
    }

    /**
     * Record one property change, resolving ids to names at write time so the feed still
     * reads correctly after a state is renamed or a parent is deleted.
     */
    private function log(WorkItem $item, User $actor, string $field, ?string $old, ?string $new): void
    {
        $meta = null;

        if ($field === 'state_id') {
            $meta = [
                'old_label' => $old ? ProjectItemState::find($old)?->name : null,
                'new_label' => $new ? ProjectItemState::find($new)?->name : null,
            ];
        } elseif ($field === 'parent_id') {
            $meta = [
                'old_label' => $old ? WorkItem::find($old)?->identifier : null,
                'new_label' => $new ? WorkItem::find($new)?->identifier : null,
            ];
        } elseif ($field === 'estimate_value_id') {
            // §31: "changed Estimate from 3 to 5". The LABEL is resolved now and frozen into
            // the row, so renaming or archiving a value later cannot rewrite the history.
            $meta = [
                'old_label' => $old ? EstimateValue::find($old)?->label : null,
                'new_label' => $new ? EstimateValue::find($new)?->label : null,
            ];
        } elseif ($field === 'epic_id') {
            // Epic §22 wants the change in history. The title is resolved NOW and frozen into
            // the row, so renaming or deleting an epic later cannot rewrite what it says.
            $meta = [
                'old_label' => $old ? Epic::find($old)?->title : null,
                'new_label' => $new ? Epic::find($new)?->title : null,
            ];
        } elseif ($field === 'cycle_id') {
            // Cycles §8.4 wants the feed to read "moved this work item from Sprint 08 to
            // Sprint 09". Names are resolved NOW and frozen into the row, so renaming or
            // deleting a cycle later cannot rewrite what the history says happened.
            $meta = [
                'old_label' => $old ? Cycle::find($old)?->name : null,
                'new_label' => $new ? Cycle::find($new)?->name : null,
            ];
        }

        // The description is rich text and can run to 20k of markup. Storing both sides of
        // every edit verbatim would bloat the feed with HTML nobody reads, so history keeps a
        // short plain-text excerpt instead — enough to see what the change was about.
        if ($field === 'description') {
            $old = $this->richText->excerpt($old);
            $new = $this->richText->excerpt($new);
        }

        $this->activity->record($item, $actor, WorkItemActivity::EVENT_UPDATED, [
            // §6's Transition feed is exactly the rows where field = 'state'.
            'field' => match ($field) {
                'state_id' => 'state',
                'cycle_id' => 'cycle',
                'epic_id' => 'epic',
                'estimate_value_id' => 'estimate',
                default => $field,
            },
            'old_value' => $old,
            'new_value' => $new,
            'meta' => $meta,
        ]);
    }

    /**
     * Names for a set of assignee or label ids, resolved now and frozen into the audit row —
     * renaming a label later must not rewrite what the history says happened.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function displayNames(string $relation, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return match ($relation) {
            'assignees' => User::whereIn('id', $ids)->get()->map(fn (User $u) => $u->displayName())->values()->all(),
            'modules' => Module::whereIn('id', $ids)->pluck('title')->values()->all(),
            default => ProjectItemLabel::whereIn('id', $ids)->pluck('name')->values()->all(),
        };
    }

    /**
     * Write the state movement behind a state change (§10.3/§10.5).
     *
     * State names are copied in, not just their ids: states are renameable and deletable, and
     * a workflow history that changes meaning when someone edits a column is not history.
     */
    private function recordTransition(WorkItem $item, User $actor, ?string $fromId, ?string $toId): void
    {
        $from = $fromId ? ProjectItemState::find($fromId) : null;
        $to = $toId ? ProjectItemState::find($toId) : null;

        WorkItemTransition::create([
            'project_id' => $item->project_id,
            'work_item_id' => $item->id,
            'from_state_id' => $from?->id,
            'to_state_id' => $to?->id,
            'from_state_name' => $from?->name,
            'to_state_name' => $to?->name,
            'actor_id' => $actor->id,
            'transitioned_at' => now(),
        ]);
    }

    /**
     * Sync a many-to-many and log it only if the membership actually changed.
     *
     * @param  array<int, int>  $ids
     */
    private function syncRelation(WorkItem $item, User $actor, string $relation, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $key = match ($relation) {
            'assignees' => 'users.id',
            'modules' => 'modules.id',
            default => 'project_item_labels.id',
        };
        $before = $item->{$relation}()->pluck($key)
            ->map(fn ($id) => (int) $id)->sort()->values()->all();

        $after = collect($ids)->sort()->values()->all();
        if ($before === $after) {
            return;
        }

        $item->{$relation}()->sync($ids);
        $item->unsetRelation($relation);

        if ($relation === 'assignees') {
            $this->notifyNewAssignees($item, $actor, $before, $after);
        }

        // Resolve BOTH sides to display values at write time. The value columns keep the ids
        // — that is the machine record — but History renders before → after, and an audit
        // trail that reads "None → 4" is telling the reader a database id.
        $this->activity->record($item, $actor, WorkItemActivity::EVENT_UPDATED, [
            'field' => $relation,
            'old_value' => implode(',', $before) ?: null,
            'new_value' => implode(',', $after) ?: null,
            'meta' => [
                'old_labels' => $this->displayNames($relation, $before),
                'new_labels' => $this->displayNames($relation, $after),
            ],
        ]);
    }

    /**
     * Mail whoever just became the assignee (§4.3). Only the ids that were not there before
     * are told, so re-saving the same assignee — or clearing one — sends nothing.
     *
     * @param  array<int, int>  $before
     * @param  array<int, int>  $after
     */
    private function notifyNewAssignees(WorkItem $item, User $actor, array $before, array $after): void
    {
        // §29's two halves, from one diff. Removing somebody clears the unread entry that
        // asked them to review an assignment they no longer have (§28); adding somebody
        // gives them theirs.
        $this->inbox->unassigned($item, array_values(array_diff($before, $after)));

        $added = array_values(array_diff($after, $before));
        if ($added === []) {
            return;
        }

        foreach (User::whereIn('id', $added)->get() as $assignee) {
            $this->assignments->assigned($item, $assignee, $actor);
            $this->inbox->assigned($item, $assignee, $actor);
        }
    }
}
