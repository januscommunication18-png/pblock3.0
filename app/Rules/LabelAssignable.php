<?php

namespace App\Rules;

use App\Models\Project;
use App\Models\ProjectItemLabel;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * May this label be put on this work item right now (Project-Level Labels §3, §11)?
 *
 * Two refusals, both server-side because the UI hides rather than blocks:
 *
 * 1. **Labels is switched off for the project.** §3 hides the field from create and detail;
 *    existing assignments are untouched (§28.5), but nothing new is accepted while it is off.
 * 2. **The label is archived.** §11/§28.9: an archived label cannot normally be assigned to
 *    new work items, while staying visible on the historical ones already carrying it.
 *
 * `$current` exempts labels the item ALREADY has, so saving an unrelated property on an item
 * carrying an archived label is not refused — the request echoes the whole set back every
 * time, and only what is genuinely being *added* should be judged. Removal is a different
 * question this rule cannot see; it is handled in UpdateWorkItemRequest::after().
 */
class LabelAssignable implements ValidationRule
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

        if (! $this->project?->featureEnabled('labels')) {
            $fail('Labels are disabled for this project.');

            return;
        }

        $label = ProjectItemLabel::query()->where('project_id', $this->project->id)->find($value);

        // A label from another project is reported by the `exists` rule alongside this one;
        // there is nothing useful to add here.
        if ($label && $label->isArchived()) {
            $fail('That label is archived and can no longer be assigned.');
        }
    }
}
