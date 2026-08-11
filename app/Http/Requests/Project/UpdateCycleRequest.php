<?php

namespace App\Http\Requests\Project;

/**
 * Edit Cycle (Cycles §9.1/§9.2). Identical rules to creating one, except the cycle being
 * edited is excluded from the overlap check — a range always overlaps itself.
 *
 * Extending rather than duplicating keeps §6.2's validation in one place: a rule added to
 * create can never silently go missing on edit.
 */
class UpdateCycleRequest extends StoreCycleRequest
{
    public function authorize(): bool
    {
        $cycle = $this->route('cycle');

        return $cycle !== null && $this->user()->can('update', $cycle);
    }

    protected function cycleBeingEdited(): ?int
    {
        return $this->route('cycle')?->id;
    }
}
