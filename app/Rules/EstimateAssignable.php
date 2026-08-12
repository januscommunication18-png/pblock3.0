<?php

namespace App\Rules;

use App\Models\EstimateValue;
use App\Models\Project;
use App\Models\ProjectEstimation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * May this work item be given this estimate right now (Estimation §4, §21, §26)?
 *
 * Three refusals, all server-side because the UI hides rather than blocks:
 *
 * 1. **Estimation is switched off.** §26 removes the chip from work item forms; the stored
 *    value survives (§27), but nothing new is accepted while it is off.
 * 2. **The value belongs to another project's system.** §9 gives each project one system, and
 *    an id from someone else's must not cross over.
 * 3. **The value is archived.** §21 keeps a removed value out of new assignments while the
 *    work items already carrying it keep reading correctly.
 *
 * `$current` exempts the value the item ALREADY has, so saving an unrelated property on an
 * item estimated with a since-archived value is not refused — the form echoes the field back
 * on every save, and only a genuine change should be judged.
 *
 * NOTE: a *clear* never reaches this rule — `estimate_value_id` is `nullable`, and Laravel
 * skips every remaining rule for a null value. Removing an estimate is §15's explicit
 * behaviour and is allowed, so unlike Epic and Cycle there is nothing to enforce in the
 * request's after-hook.
 */
class EstimateAssignable implements ValidationRule
{
    public function __construct(
        private readonly ?Project $project,
        private readonly int|string|null $current = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // §15: "No Estimate" is a first-class choice, and it stays available.
        if ($value === null || $value === '') {
            return;
        }

        if ($this->current !== null && (string) $value === (string) $this->current) {
            return;
        }

        if (! $this->project?->featureEnabled('estimates')) {
            $fail('Estimation is not enabled for this project.');

            return;
        }

        $estimationId = ProjectEstimation::query()->where('project_id', $this->project->id)->value('id');
        $estimate = EstimateValue::query()->whereKey($value)->first();

        if (! $estimate || (int) $estimate->project_estimation_id !== (int) $estimationId) {
            $fail('That estimate is not part of this project\'s estimation system.');

            return;
        }

        if (! $estimate->active) {
            $fail('That estimate has been removed and can no longer be assigned.');
        }
    }
}
