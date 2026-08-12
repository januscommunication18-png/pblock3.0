<?php

namespace App\Rules;

use App\Models\Epic;
use App\Models\Project;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * May this work item be put in this epic right now (Epic §16 / §21)?
 *
 * §21 requires the API to confirm the epic exists, belongs to the same project, and that the
 * user may assign it. The first two are the `exists` rule sitting alongside this one; this
 * covers the two states the UI hides rather than blocks:
 *
 * 1. **Epics is switched off for the project.** The assignment FREEZES: no new epic, no
 *    change of epic, and no clearing either. Disable is a configuration change, never a
 *    delete — an existing association has to survive it, and a user who could clear the field
 *    while the feature was off would be deleting exactly the historical data disable is
 *    supposed to protect.
 * 2. **The epic is archived.** §16 keeps archived epics out of active selectors.
 *
 * `$current` exempts the epic the item is ALREADY in, so saving an unrelated property on an
 * item that sits in an archived — or now frozen — epic is not refused. The form echoes the
 * field back on every save, and only a genuine change should be judged.
 *
 * This is where Epic and Module deliberately part company: ModuleAssignable lets a work item
 * be cleared out of a module while Modules is off, because a module link is a row that can be
 * re-added. Epic's disable rules say the opposite in as many words, so it holds the value.
 */
class EpicAssignable implements ValidationRule
{
    public function __construct(
        private readonly ?Project $project,
        private readonly int|string|null $current = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $unchanged = $this->current !== null
            ? (string) $value === (string) $this->current
            : ($value === null || $value === '');

        // Re-sending what is already there is not a change, whatever the feature's state.
        if ($unchanged) {
            return;
        }

        // Frozen while the feature is off — including a clear, which would destroy the very
        // association the disable rules require to survive.
        if (! $this->project?->featureEnabled('epics')) {
            $fail($this->current
                ? 'Epics are disabled for this project, so this work item\'s epic cannot be changed. Enable Epics from Project Settings.'
                : 'Epics are disabled for this project. Enable Epics from Project Settings to assign one.');

            return;
        }

        // §9: clearing removes the relationship without deleting the epic.
        if ($value === null || $value === '') {
            return;
        }

        $epic = Epic::query()->forProject($this->project->id)->find($value);

        // An epic from another project is reported by the `exists` rule alongside this one;
        // there is nothing useful to add here.
        if ($epic && $epic->isArchived()) {
            $fail('That epic is archived. Restore it before adding work to it.');
        }
    }
}
