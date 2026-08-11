<?php

namespace App\Rules;

use App\Models\Cycle;
use App\Models\Project;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * May this work item be planned into this cycle right now (Cycles §3.2.4 / §8.3.5)?
 *
 * Two refusals, both of which have to be server-side because the UI hides rather than blocks:
 *
 * 1. **Cycles is switched off for the project.** §3.2.4 says the property is no longer
 *    available for new assignment — existing associations are untouched, but nothing new is
 *    accepted while the feature is off.
 * 2. **The cycle has already finished.** §8.3.5: completed cycles do not normally accept new
 *    work. Sprint history stops being history if items can still be dropped into it.
 *
 * `$currentCycleId` exempts a value that is not actually changing. Without it, saving an
 * unrelated property — a comment, a priority — on an item that already sits in a finished
 * cycle would be refused because the request echoes the cycle back unchanged.
 */
class CycleAssignable implements ValidationRule
{
    public function __construct(
        private readonly ?Project $project,
        private readonly ?int $currentCycleId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Clearing the cycle is always allowed (§8.3.7), including while the feature is off —
        // otherwise turning Cycles off would trap items in a cycle nobody can see.
        if ($value === null || $value === '') {
            return;
        }

        if ((int) $value === (int) $this->currentCycleId) {
            return;
        }

        if (! $this->project?->featureEnabled('cycles')) {
            $fail('Cycles are not enabled for this project.');

            return;
        }

        $cycle = Cycle::query()->forProject($this->project->id)->find($value);

        // A cycle that does not belong to this project is reported by the `exists` rule
        // alongside this one; there is nothing useful to add here.
        if ($cycle && $cycle->isCompleted()) {
            $fail('That cycle has already ended. Choose an active or upcoming cycle.');
        }
    }
}
