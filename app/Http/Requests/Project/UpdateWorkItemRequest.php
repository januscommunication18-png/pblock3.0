<?php

namespace App\Http\Requests\Project;

use App\Models\WorkspaceMembership;
use App\Rules\CycleAssignable;
use App\Rules\EpicAssignable;
use App\Rules\EstimateAssignable;
use App\Rules\LabelAssignable;
use App\Rules\ModuleAssignable;
use App\Rules\ParentAssignable;
use App\Services\RichTextSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inline work-item edits (spec §4.2) — one or many properties at a time, from the row chips
 * or the Edit modal. Every field is `sometimes`, so a chip can PATCH just what it changed.
 *
 * The current values are merged in before validation so cross-field rules still hold: a
 * request that sends only `due_date` is still checked against the item's stored start date.
 */
class UpdateWorkItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $item = $this->route('workItem');

        if ($this->has('title')) {
            $this->merge(['title' => trim((string) $this->input('title'))]);
        }

        // Rich text from the editor — sanitized before it is validated or stored, so the
        // markup that reaches other people's browsers is always the cleaned version.
        if ($this->has('description')) {
            $this->merge(['description' => app(RichTextSanitizer::class)->sanitize($this->input('description'))]);
        }

        // Fill the missing half of the date pair from the stored value, so `after:start_date`
        // compares against reality rather than against a null the client never sent.
        if ($item && ($this->has('start_date') xor $this->has('due_date'))) {
            $this->merge($this->has('due_date')
                ? ['start_date' => $item->start_date?->format('Y-m-d')]
                : ['due_date' => $item->due_date?->format('Y-m-d')]);
        }

        foreach (['state_id', 'parent_id', 'cycle_id', 'epic_id', 'estimate_value_id', 'start_date', 'due_date'] as $nullable) {
            if ($this->has($nullable) && ! $this->filled($nullable)) {
                $this->merge([$nullable => null]);
            }
        }
    }

    /**
     * The removals a per-value rule cannot see (Feature Disable §1, §3, §4).
     *
     * While a feature is off its work item assignment FREEZES — no new value, no change, and
     * no removal. Two of those three are enforced by the per-value rules; removal is not, for
     * two different reasons:
     *
     *  - **epic_id / cycle_id** are `nullable`, and Laravel skips every remaining rule for an
     *    attribute whose value is null. The rule never runs on a clear.
     *  - **module_ids** is a set, and each rule call sees one id. An id dropped from the list
     *    simply never arrives, so there is nothing for a rule to judge.
     *
     * Both need the whole payload, which is what an after-hook has and a rule does not. Only
     * the gap is handled here; everything a rule CAN see stays in the rule, so no refusal
     * produces two messages.
     */
    public function after(): array
    {
        return [
            function ($validator) {
                $item = $this->route('workItem');
                $project = $this->route('project');

                if (! $item || ! $project) {
                    return;
                }

                // One epic, one cycle: a present-but-empty value is a removal.
                foreach (['epic_id' => 'epics', 'cycle_id' => 'cycles'] as $field => $feature) {
                    if (! $item->{$field} || ! $this->has($field) || $this->filled($field)) {
                        continue;
                    }
                    if ($project->featureEnabled($feature)) {
                        continue;
                    }

                    $label = config("projects.features.{$feature}.label");
                    $validator->errors()->add($field,
                        "{$label} are disabled for this project, so this work item's ".
                        rtrim(strtolower($label), 's')." cannot be removed. Enable {$label} in Project Settings.");
                }

                // Modules and labels are SETS: a removal is an id that is stored but missing
                // from the submitted list, which no per-value rule can see.
                foreach ([
                    // field, feature, relation, pivot-qualified id column, label
                    ['module_ids', 'modules', 'modules', 'modules.id', 'Modules'],
                    ['label_ids', 'labels', 'labels', 'project_item_labels.id', 'Labels'],
                ] as [$field, $feature, $relation, $column, $label]) {
                    if (! $this->has($field) || $project->featureEnabled($feature)) {
                        continue;
                    }

                    $submitted = array_map('intval', (array) $this->input($field, []));
                    $stored = $item->{$relation}()->pluck($column)->map('intval')->all();

                    if (array_diff($stored, $submitted) !== []) {
                        $validator->errors()->add($field,
                            "{$label} are disabled for this project, so this work item's ".
                            strtolower($label)." cannot be removed. Enable {$label} in Project Settings.");
                    }
                }
            },
        ];
    }

    public function rules(): array
    {
        $item = $this->route('workItem');
        $projectId = $this->route('project')?->id;
        $workspaceId = $this->user()->current_workspace_id;

        return [
            'title' => ['sometimes', 'required', 'string', 'max:'.config('projects.work_item_title_max')],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.config('projects.work_item_description_max')],
            'state_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('project_item_states', 'id')->where('project_id', $projectId),
            ],
            'priority' => ['sometimes', 'required', Rule::in(array_keys(config('projects.work_item_priorities')))],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date', 'after:start_date'],
            'parent_id' => [
                'sometimes', 'nullable', 'integer',
                // A work item can never be its own parent (§5) — nor sit under one of its own
                // descendants, which ParentAssignable checks by walking the chain (§25).
                Rule::notIn([$item?->id]),
                Rule::exists('work_items', 'id')->where('project_id', $projectId),
                new ParentAssignable($item),
            ],
            // Cycles §8.3: one cycle at a time, project-scoped, and never a finished one.
            // Selecting a different cycle MOVES the item — there is nothing to remove first,
            // because the association is this single column.
            'cycle_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('cycles', 'id')->where('project_id', $projectId),
                new CycleAssignable($this->route('project'), $item?->cycle_id),
            ],
            // Estimation §11/§33: one estimate, from this project's own system. The rest
            // of the check lives in EstimateAssignable.
            'estimate_value_id' => [
                'sometimes', 'nullable', 'integer',
                new EstimateAssignable($this->route('project'), $item?->estimate_value_id),
            ],
            // Epic §9: zero or one epic, and §11/§12 make it independent — changing it here
            // neither reads nor writes cycle_id or the module pivot.
            'epic_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('epics', 'id')->where('project_id', $projectId)->whereNull('deleted_at'),
                new EpicAssignable($this->route('project'), $item?->epic_id),
            ],
            // A work item has ONE owner (§4.3, revised): the picker replaces rather than
            // adds, and the array shape is kept so the client contract does not change.
            'assignee_ids' => ['sometimes', 'array', 'max:1'],
            'assignee_ids.*' => [
                'integer',
                Rule::exists('workspace_memberships', 'user_id')
                    ->where('workspace_id', $workspaceId)
                    ->where('status', WorkspaceMembership::STATUS_ACTIVE),
            ],
            // Modules §9.3: a work item may belong to SEVERAL modules, so this is a list
            // rather than a single id like `cycle_id`.
            // ONE module at a time (module-management.md). `max:1` rather than a scalar
            // `module_id`: the payload stays a set, so restoring several modules later is a
            // number change here instead of a schema and API change everywhere.
            'module_ids' => ['sometimes', 'array', 'max:1'],
            'module_ids.*' => [
                'integer',
                Rule::exists('modules', 'id')->where('project_id', $projectId)->whereNull('deleted_at'),
                new ModuleAssignable(
                    $this->route('project'),
                    $item ? $item->modules()->pluck('modules.id')->all() : [],
                ),
            ],
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => [
                'integer',
                Rule::exists('project_item_labels', 'id')->where('project_id', $projectId),
                new LabelAssignable($this->route('project'), $item?->labels->pluck('id')->all() ?? []),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'due_date.after' => 'The due date must be after the start date.',
            'parent_id.not_in' => 'A work item cannot be its own parent.',
            'state_id.exists' => 'That state is not configured for this project.',
            'cycle_id.exists' => 'That cycle does not belong to this project.',
            'module_ids.*.exists' => 'That module does not belong to this project.',
        ];
    }
}
