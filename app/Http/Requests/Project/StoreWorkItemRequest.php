<?php

namespace App\Http\Requests\Project;

use App\Models\WorkspaceMembership;
use App\Rules\CycleAssignable;
use App\Rules\EpicAssignable;
use App\Rules\EstimateAssignable;
use App\Rules\LabelAssignable;
use App\Rules\ModuleAssignable;
use App\Services\RichTextSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create Work Item validation (spec §4.3 / §12). Authorization lives in the controller;
 * this request normalizes input and enforces that every referenced record — state, labels,
 * assignees, parent — belongs to *this* project/workspace, so a crafted payload cannot
 * reach across tenants (requirements §7).
 */
class StoreWorkItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->input('title')),
            // The create modal has a plain textarea, not the rich-text editor, so what
            // arrives here is literal text: it is converted to paragraphs rather than parsed
            // as markup. That also means a description posted straight to the API cannot
            // smuggle HTML in through the one field that is not edited in the editor.
            'description' => app(RichTextSanitizer::class)->fromPlainText($this->input('description')),
            // The modal posts "" for every untouched picker.
            'state_id' => $this->filled('state_id') ? $this->input('state_id') : null,
            'parent_id' => $this->filled('parent_id') ? $this->input('parent_id') : null,
            'cycle_id' => $this->filled('cycle_id') ? $this->input('cycle_id') : null,
            'epic_id' => $this->filled('epic_id') ? $this->input('epic_id') : null,
            'estimate_value_id' => $this->filled('estimate_value_id') ? $this->input('estimate_value_id') : null,
            'start_date' => $this->filled('start_date') ? $this->input('start_date') : null,
            'due_date' => $this->filled('due_date') ? $this->input('due_date') : null,
            'priority' => $this->filled('priority') ? $this->input('priority') : 'none',
            'assignee_ids' => $this->normalizeIds($this->input('assignee_ids')),
            'module_ids' => $this->normalizeIds($this->input('module_ids')),
            'label_ids' => $this->normalizeIds($this->input('label_ids')),
        ]);
    }

    public function rules(): array
    {
        $project = $this->route('project');
        $projectId = $project?->id;
        $workspaceId = $this->user()->current_workspace_id;

        return [
            'title' => ['required', 'string', 'max:'.config('projects.work_item_title_max')],
            'description' => ['nullable', 'string', 'max:'.config('projects.work_item_description_max')],
            // States and labels are project-scoped sets, so both are checked against this project.
            'state_id' => [
                'nullable', 'integer',
                Rule::exists('project_item_states', 'id')->where('project_id', $projectId),
            ],
            'priority' => ['required', Rule::in(array_keys(config('projects.work_item_priorities')))],
            'start_date' => ['nullable', 'date'],
            // Spec §4.3: a start date cannot logically exceed the due date. `after`, not
            // `after_or_equal` — the due date must fall strictly after the start date, which
            // is also what the picker enforces by disabling the start day and everything
            // before it. A due date on its own (no start date) stays valid.
            'due_date' => ['nullable', 'date', 'after:start_date'],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('work_items', 'id')->where('project_id', $projectId),
            ],
            // Cycles §8.3.6: project-scoped, so a crafted payload cannot plan this item into
            // another project's sprint. The rest of the cycle rules live in CycleAssignable.
            'cycle_id' => [
                'nullable', 'integer',
                Rule::exists('cycles', 'id')->where('project_id', $projectId),
                new CycleAssignable($project),
            ],
            // Estimation §11/§33: one estimate, from this project's own system. The rest
            // of the check lives in EstimateAssignable.
            'estimate_value_id' => [
                'nullable', 'integer',
                new EstimateAssignable($project),
            ],
            // Epic §9: zero or one epic, project-scoped so a crafted payload cannot file this
            // item under another project's initiative. The rest lives in EpicAssignable.
            'epic_id' => [
                'nullable', 'integer',
                Rule::exists('epics', 'id')->where('project_id', $projectId)->whereNull('deleted_at'),
                new EpicAssignable($project),
            ],
            // One assignee per work item (§4.3, revised) — see UpdateWorkItemRequest.
            'assignee_ids' => ['array', 'max:1'],
            'assignee_ids.*' => [
                'integer',
                Rule::exists('workspace_memberships', 'user_id')
                    ->where('workspace_id', $workspaceId)
                    ->where('status', WorkspaceMembership::STATUS_ACTIVE),
            ],
            // Modules §9.1: a work item can be created already in one or more modules.
            'module_ids' => ['array', 'max:50'],
            'module_ids.*' => [
                'integer',
                Rule::exists('modules', 'id')->where('project_id', $projectId)->whereNull('deleted_at'),
                new ModuleAssignable($project),
            ],
            'label_ids' => ['array'],
            'label_ids.*' => [
                'integer',
                Rule::exists('project_item_labels', 'id')->where('project_id', $projectId),
                new LabelAssignable($project, []),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Give the work item a title.',
            'due_date.after' => 'The due date must be after the start date.',
            'state_id.exists' => 'That state is not configured for this project.',
            'label_ids.*.exists' => 'One of those labels is not configured for this project.',
            'assignee_ids.*.exists' => 'Assignees must be active members of this workspace.',
            'parent_id.exists' => 'The parent work item must belong to this project.',
            'cycle_id.exists' => 'That cycle does not belong to this project.',
            'epic_id.exists' => 'That epic does not belong to this project.',
            'module_ids.*.exists' => 'That module does not belong to this project.',
        ];
    }

    /** @return array<int, int> */
    private function normalizeIds($value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()->values()->all();
    }
}
