<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Models\WorkItemLink;
use App\Models\WorkItemRelation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sub-tasks, dependencies and relations between work items (Collaboration spec §19–§36).
 *
 * Three rules shape this class:
 *
 * 1. **One stored direction.** `blocking` is stored; `blocked_by` is that row read from the
 *    other end (§52). Asking to add a `blocked_by` therefore writes a `blocking` row the
 *    other way round. `related` is symmetric and stored once, from whichever side asked.
 * 2. **Parent/child is not a relation row.** Sub-tasks are `work_items.parent_id`, which the
 *    list and create modal already use. This class sets and clears that column and applies
 *    §25's rules to it.
 * 3. **Both ends must be in the same workspace** (§56), and every change writes an activity
 *    entry (§36/§45) — a relationship that appears with no record of who added it is the
 *    kind of thing people argue about later.
 */
class WorkItemRelationManager
{
    public function __construct(
        private readonly WorkItemActivityRecorder $activity,
        private readonly WorkItemBlockedNotifier $blocked,
    ) {}

    // ---------------------------------------------------------------- sub-tasks (§19–§26)

    /**
     * Attach existing work items as sub-tasks of `$parent` (§22).
     *
     * @param  array<int, int>  $childIds
     * @return int how many were newly attached
     */
    public function addSubtasks(WorkItem $parent, User $actor, array $childIds): int
    {
        $children = $this->resolve($parent, $childIds);
        $attached = 0;

        DB::transaction(function () use ($parent, $actor, $children, &$attached) {
            foreach ($children as $child) {
                $this->assertCanParent($parent, $child);

                if ((int) $child->parent_id === (int) $parent->id) {
                    continue; // already a sub-task — adding twice is not an error, it is a no-op (§25)
                }

                $child->forceFill(['parent_id' => $parent->id])->save();
                $attached++;

                // Logged on BOTH items: the child's parent changed, and the parent gained a
                // sub-task. Each panel should be able to explain itself without the other.
                $this->log($child, $actor, 'parent', null, $parent->identifier);
                $this->log($parent, $actor, 'subtask_added', null, $child->identifier);
            }
        });

        return $attached;
    }

    /** Detach a sub-task (§26). The work item itself is never deleted. */
    public function removeSubtask(WorkItem $parent, WorkItem $child, User $actor): void
    {
        if ((int) $child->parent_id !== (int) $parent->id) {
            return;
        }

        DB::transaction(function () use ($parent, $child, $actor) {
            $child->forceFill(['parent_id' => null])->save();
            $this->log($child, $actor, 'parent', $parent->identifier, null);
            $this->log($parent, $actor, 'subtask_removed', $child->identifier, null);
        });
    }

    /**
     * §25: a work item cannot be its own parent, and a parent cannot be adopted by its own
     * descendant — that would make a cycle no view could render without looping.
     */
    public function assertCanParent(WorkItem $parent, WorkItem $child): void
    {
        if ((int) $parent->id === (int) $child->id) {
            throw ValidationException::withMessages([
                'work_item_ids' => 'A work item cannot be its own sub-task.',
            ]);
        }

        // Walk up from the intended parent: meeting the child means this would close a loop.
        $seen = [];
        $cursor = $parent;
        while ($cursor && $cursor->parent_id && ! isset($seen[$cursor->id])) {
            $seen[$cursor->id] = true;
            if ((int) $cursor->parent_id === (int) $child->id) {
                throw ValidationException::withMessages([
                    'work_item_ids' => "{$child->identifier} is already above {$parent->identifier}, so it cannot become its sub-task.",
                ]);
            }
            $cursor = WorkItem::find($cursor->parent_id);
        }
    }

    // ------------------------------------------------------- dependencies & relations (§27–§36)

    /**
     * Add one relation type against several work items (§30/§34).
     *
     * @param  array<int, int>  $relatedIds
     * @return int how many were newly created
     */
    public function addRelations(WorkItem $item, User $actor, string $type, array $relatedIds): int
    {
        if (! in_array($type, WorkItemRelation::ADDABLE, true)) {
            throw ValidationException::withMessages(['relation_type' => 'Unknown relation type.']);
        }

        $targets = $this->resolve($item, $relatedIds);
        $created = 0;
        // Collected per newly blocked item, so one dependency dialog that blocks something
        // with three blockers sends one mail listing all three, not three mails.
        $newlyBlocked = [];

        DB::transaction(function () use ($item, $actor, $type, $targets, &$created, &$newlyBlocked) {
            foreach ($targets as $target) {
                if ((int) $target->id === (int) $item->id) {
                    throw ValidationException::withMessages([
                        'work_item_ids' => 'A work item cannot be related to itself.',
                    ]);
                }

                // Fold the request onto the stored direction (§52).
                [$from, $to, $stored] = $this->canonical($item, $target, $type);

                if ($this->contradicts($from, $to, $stored)) {
                    throw ValidationException::withMessages([
                        'work_item_ids' => "{$from->identifier} and {$to->identifier} already have the opposite dependency. Remove that one first.",
                    ]);
                }

                if ($this->exists($from, $to, $stored)) {
                    continue; // §31/§36: duplicates are a no-op, not an error
                }

                WorkItemRelation::create([
                    'project_id' => $from->project_id,
                    'work_item_id' => $from->id,
                    'related_work_item_id' => $to->id,
                    'relation_type' => $stored,
                    'created_by' => $actor->id,
                ]);
                $created++;

                if ($stored === WorkItemRelation::TYPE_BLOCKING) {
                    // `$to` is the item that just became blocked, whichever direction the
                    // request came from.
                    $newlyBlocked[$to->id]['item'] = $to;
                    $newlyBlocked[$to->id]['blockers'][] = $from;
                }

                // Both items show the relation, so both records it.
                $this->log($item, $actor, 'relation_added', null, $this->label($type).' '.$target->identifier);
                $this->log($target, $actor, 'relation_added', null, $this->label($this->inverse($type)).' '.$item->identifier);
            }
        });

        // §58: outside the transaction's write path, but the notifier defers delivery to
        // after commit — a rolled-back dependency must not announce itself.
        foreach ($newlyBlocked as $entry) {
            $this->blocked->blocked($entry['item'], $entry['blockers'], $actor);
        }

        return $created;
    }

    /** Remove a relation from either end (§32) — one row, so one delete clears both views. */
    public function removeRelation(WorkItemRelation $relation, User $actor): void
    {
        DB::transaction(function () use ($relation, $actor) {
            $from = $relation->workItem;
            $to = $relation->relatedWorkItem;
            $type = $relation->relation_type;

            $relation->delete();

            if ($from) {
                $this->log($from, $actor, 'relation_removed', $this->label($type).' '.($to?->identifier ?? ''), null);
            }
            if ($to) {
                $this->log($to, $actor, 'relation_removed', $this->label($this->inverse($type)).' '.($from?->identifier ?? ''), null);
            }
        });
    }

    // ------------------------------------------------------------------------ reading

    /**
     * Everything the detail panel's structure sections need, in one read (§66).
     *
     * @return array<string, mixed>
     */
    public function structureFor(WorkItem $item): array
    {
        $subtasks = WorkItem::query()
            ->where('parent_id', $item->id)
            ->with(['state', 'assignees'])
            ->orderBy('sequence_no')
            ->get();

        // Every relation this item touches, in ONE query. Asking per group instead meant six
        // round trips, each dragging its own eager loads, to fill a panel that is usually
        // mostly empty — the single biggest cost in opening a work item.
        $relations = $this->allRelations($item);

        return [
            'subtasks' => [
                'items' => $subtasks->map(fn (WorkItem $c) => $this->row($c))->values()->all(),
                'progress' => $this->progress($subtasks),
            ],
            'dependencies' => [
                'blocking' => $relations[WorkItemRelation::TYPE_BLOCKING],
                'blocked_by' => $relations[WorkItemRelation::TYPE_BLOCKED_BY],
            ],
            'relations' => [
                'related' => $relations[WorkItemRelation::TYPE_RELATED],
                'duplicate_of' => $relations[WorkItemRelation::TYPE_DUPLICATE_OF],
                'duplicated_by' => $relations[WorkItemRelation::TYPE_DUPLICATED_BY],
            ],
            'links' => WorkItemLink::query()
                ->where('work_item_id', $item->id)
                ->latest('id')
                ->get()
                ->map(fn (WorkItemLink $l) => [
                    'id' => $l->id,
                    'url' => $l->url,
                    'title' => $l->title,
                    'label' => $l->label(),
                    // ISO as well as formatted: the panel shows "3 hours ago", which it can
                    // only work out from a real timestamp.
                    'created_at' => optional($l->created_at)->toIso8601String(),
                    'added' => optional($l->created_at)->format('M j, Y'),
                ])->values()->all(),
        ];
    }

    /**
     * Sub-task progress (§24). "Complete" is decided by the state's stable GROUP, never by a
     * state name — names are user-editable, so a project that renamed "Done" would otherwise
     * silently report 0%. Cancelled items are excluded from the denominator rather than
     * counted as incomplete, so abandoning work does not make a parent look stuck.
     *
     * @param  Collection<int, WorkItem>  $subtasks
     * @return array<string, int>
     */
    public function progress(Collection $subtasks): array
    {
        $counted = $subtasks->filter(fn (WorkItem $c) => $c->state?->group !== 'cancelled');
        $total = $counted->count();
        $done = $counted->filter(fn (WorkItem $c) => $c->state?->group === 'completed')->count();

        return [
            'completed' => $done,
            'total' => $total,
            'cancelled' => $subtasks->count() - $total,
            'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
        ];
    }

    // ------------------------------------------------------------------------ internals

    /**
     * Load the requested work items and refuse anything outside this workspace (§56).
     *
     * Cross-PROJECT is allowed (§57 offers it as a search scope); cross-WORKSPACE is not, and
     * the tenant scope on WorkItem already makes it impossible to load one — this turns that
     * silence into a clear message.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, WorkItem>
     */
    private function resolve(WorkItem $item, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $found = WorkItem::query()->whereIn('id', $ids)->get();

        if ($found->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'work_item_ids' => 'One or more of those work items could not be found in this workspace.',
            ]);
        }

        return $found;
    }

    /**
     * Fold a requested relation onto the direction that is actually stored.
     *
     * @return array{0: WorkItem, 1: WorkItem, 2: string}
     */
    private function canonical(WorkItem $item, WorkItem $target, string $type): array
    {
        return match ($type) {
            // "A is blocked by B" is stored as "B blocks A".
            WorkItemRelation::TYPE_BLOCKED_BY => [$target, $item, WorkItemRelation::TYPE_BLOCKING],
            // Symmetric: store it once, from the side that asked.
            WorkItemRelation::TYPE_RELATED => [$item, $target, WorkItemRelation::TYPE_RELATED],
            default => [$item, $target, $type],
        };
    }

    private function inverse(string $type): string
    {
        return match ($type) {
            WorkItemRelation::TYPE_BLOCKING => WorkItemRelation::TYPE_BLOCKED_BY,
            WorkItemRelation::TYPE_BLOCKED_BY => WorkItemRelation::TYPE_BLOCKING,
            WorkItemRelation::TYPE_DUPLICATE_OF => WorkItemRelation::TYPE_DUPLICATED_BY,
            WorkItemRelation::TYPE_DUPLICATED_BY => WorkItemRelation::TYPE_DUPLICATE_OF,
            default => WorkItemRelation::TYPE_RELATED,
        };
    }

    /** Human label used in activity lines. */
    private function label(string $type): string
    {
        return match ($type) {
            WorkItemRelation::TYPE_BLOCKING => 'blocking',
            WorkItemRelation::TYPE_BLOCKED_BY => 'blocked by',
            WorkItemRelation::TYPE_DUPLICATE_OF => 'duplicate of',
            WorkItemRelation::TYPE_DUPLICATED_BY => 'duplicated by',
            default => 'related to',
        };
    }

    private function exists(WorkItem $from, WorkItem $to, string $stored): bool
    {
        $query = WorkItemRelation::query()->where('relation_type', $stored);

        // `related` is symmetric, so a row either way round is the same relationship.
        if ($stored === WorkItemRelation::TYPE_RELATED) {
            return $query->where(function ($q) use ($from, $to) {
                $q->where(fn ($w) => $w->where('work_item_id', $from->id)->where('related_work_item_id', $to->id))
                    ->orWhere(fn ($w) => $w->where('work_item_id', $to->id)->where('related_work_item_id', $from->id));
            })->exists();
        }

        return $query->where('work_item_id', $from->id)
            ->where('related_work_item_id', $to->id)
            ->exists();
    }

    /** §31: the same pair may not block in both directions at once. */
    private function contradicts(WorkItem $from, WorkItem $to, string $stored): bool
    {
        if ($stored !== WorkItemRelation::TYPE_BLOCKING) {
            return false;
        }

        return WorkItemRelation::query()
            ->where('relation_type', WorkItemRelation::TYPE_BLOCKING)
            ->where('work_item_id', $to->id)
            ->where('related_work_item_id', $from->id)
            ->exists();
    }

    /**
     * All of this item's relations, grouped by how they read FROM this item.
     *
     * One query, then the direction is decided in PHP: a row is "blocking" when this item is
     * on the left of a `blocking` row and "blocked by" when it is on the right — the same row
     * seen from the other end (§52). `related` is symmetric, so either side counts.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function allRelations(WorkItem $item): array
    {
        $rows = WorkItemRelation::query()
            ->where(fn ($q) => $q->where('work_item_id', $item->id)->orWhere('related_work_item_id', $item->id))
            ->with([
                'workItem.state', 'workItem.assignees',
                'relatedWorkItem.state', 'relatedWorkItem.assignees',
            ])
            ->get();

        $grouped = [
            WorkItemRelation::TYPE_BLOCKING => [],
            WorkItemRelation::TYPE_BLOCKED_BY => [],
            WorkItemRelation::TYPE_RELATED => [],
            WorkItemRelation::TYPE_DUPLICATE_OF => [],
            WorkItemRelation::TYPE_DUPLICATED_BY => [],
        ];

        foreach ($rows as $row) {
            $isLeft = (int) $row->work_item_id === (int) $item->id;
            $other = $isLeft ? $row->relatedWorkItem : $row->workItem;

            if (! $other) {
                continue;
            }

            $key = match (true) {
                $row->relation_type === WorkItemRelation::TYPE_RELATED => WorkItemRelation::TYPE_RELATED,
                $row->relation_type === WorkItemRelation::TYPE_BLOCKING => $isLeft
                    ? WorkItemRelation::TYPE_BLOCKING
                    : WorkItemRelation::TYPE_BLOCKED_BY,
                default => $isLeft
                    ? WorkItemRelation::TYPE_DUPLICATE_OF
                    : WorkItemRelation::TYPE_DUPLICATED_BY,
            };

            $grouped[$key][] = ['relation_id' => $row->id] + $this->row($other);
        }

        return $grouped;
    }

    /** @return array<string, mixed> One work item as a relationship row (§23/§30). */
    private function row(WorkItem $item): array
    {
        $state = $item->state;

        return [
            'id' => $item->id,
            'identifier' => $item->identifier,
            'title' => $item->title,
            'project_id' => $item->project_id,
            'state' => $state ? ['id' => $state->id, 'name' => $state->name, 'color' => $state->color, 'group' => $state->group] : null,
            'priority' => $item->priority,
            'due_date' => $item->due_date?->format('Y-m-d'),
            'assignees' => $item->relationLoaded('assignees')
                ? $item->assignees->map(fn (User $u) => [
                    'id' => $u->id, 'name' => $u->displayName(),
                    'initial' => $u->initial(), 'avatar_url' => $u->avatar_url,
                ])->values()->all()
                : [],
        ];
    }

    private function log(WorkItem $item, User $actor, string $field, ?string $old, ?string $new): void
    {
        $this->activity->record($item, $actor, WorkItemActivity::EVENT_UPDATED, [
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
        ]);
    }
}
