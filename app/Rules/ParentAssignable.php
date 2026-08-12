<?php

namespace App\Rules;

use App\Models\WorkItem;
use App\Services\WorkItemRelationManager;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * May this work item be given this parent (§5 / §25)?
 *
 * `Rule::notIn([$item->id])` on the request already stops an item parenting itself, but that
 * is only the shortest loop. A → B → A, or any longer chain, is just as circular and leaves a
 * tree that no renderer can walk out of.
 *
 * The sub-task panel has always been guarded, because it goes through
 * WorkItemRelationManager::addSubtasks. The Parent property writes `parent_id` straight
 * through PATCH, so until the property became editable in the UI the direct route was simply
 * unreachable. Exposing it is what makes this rule necessary.
 */
class ParentAssignable implements ValidationRule
{
    public function __construct(private readonly ?WorkItem $item) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Clearing the parent can never create a loop.
        if ($value === null || $value === '' || ! $this->item) {
            return;
        }

        $parent = WorkItem::query()->find($value);

        // A parent that does not exist, or belongs to another project, is reported by the
        // `exists` rule alongside this one; there is nothing useful to add here.
        if (! $parent) {
            return;
        }

        // Resolved here rather than injected: the manager has its own dependencies, and the
        // rule is constructed inline in the form request where no container is at hand.
        if ($reason = app(WorkItemRelationManager::class)->parentRefusal($parent, $this->item)) {
            $fail($reason);
        }
    }
}
