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
 * 1. **Cycles is switched off for the project.** The assignment FREEZES: no new cycle, no move
 *    between cycles, and no removal either (Feature Disable §4). Disabling is a configuration
 *    change, never a delete — an existing association has to survive it, and a user who could
 *    clear the field while the feature was off would be destroying exactly the historical data
 *    disable is supposed to protect.
 * 2. **The cycle has already finished.** §8.3.5: completed cycles do not normally accept new
 *    work. Sprint history stops being history if items can still be dropped into it.
 *
 * `$currentCycleId` exempts a value that is not actually changing. Without it, saving an
 * unrelated property — a comment, a priority — on an item that already sits in a finished
 * cycle would be refused because the request echoes the cycle back unchanged.
 *
 * NOTE: a *clear* never reaches this rule. `cycle_id` is `nullable`, and Laravel skips every
 * remaining rule for a null value — so the frozen-removal half is enforced in
 * UpdateWorkItemRequest::after(), where the whole payload is visible.
 */
class CycleAssignable implements ValidationRule
{
    public function __construct(
        private readonly ?Project $project,
        private readonly ?int $currentCycleId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // §8.3.7: clearing removes the association without deleting the cycle. Whether it is
        // allowed while the feature is OFF is decided in UpdateWorkItemRequest::after() — see
        // the note above; a null value never gets this far.
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
