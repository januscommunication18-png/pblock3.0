<?php

namespace App\Rules;

use App\Models\Module;
use App\Models\Project;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * May this work item be put in this module right now (Module Management §12.2 / §15)?
 *
 * Two refusals, both server-side because the UI hides rather than blocks:
 *
 * 1. **Modules is switched off for the project.** The set FREEZES: nothing added while it is
 *    off (Feature Disable §3). Removal is frozen too, but this rule cannot see a removal — it
 *    is handed one id at a time, and an id dropped from the list simply never arrives. That
 *    half lives in UpdateWorkItemRequest::after(), which compares the submitted set against
 *    what is stored.
 * 2. **The module is archived.** §12.2 keeps archived modules out of the work item picker,
 *    and §15 forbids assigning to one.
 *
 * `$current` exempts modules the item is ALREADY in, so saving an unrelated property on an
 * item that sits in an archived module is not refused — the request echoes the full list back
 * every time, and only what is genuinely being added should be judged.
 */
class ModuleAssignable implements ValidationRule
{
    /** @param  array<int, int>  $current */
    public function __construct(
        private readonly ?Project $project,
        private readonly array $current = [],
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (in_array((int) $value, array_map('intval', $this->current), true)) {
            return;
        }

        if (! $this->project?->featureEnabled('modules')) {
            $fail('Modules are not enabled for this project.');

            return;
        }

        $module = Module::query()->forProject($this->project->id)->find($value);

        // A module from another project is reported by the `exists` rule alongside this one;
        // there is nothing useful to add here.
        if ($module && $module->isArchived()) {
            $fail('That module is archived. Restore it before adding work to it.');
        }
    }
}
