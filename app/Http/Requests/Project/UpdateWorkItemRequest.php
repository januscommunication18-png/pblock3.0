<?php

namespace App\Http\Requests\Project;

use App\Models\WorkspaceMembership;
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

        foreach (['state_id', 'parent_id', 'start_date', 'due_date'] as $nullable) {
            if ($this->has($nullable) && ! $this->filled($nullable)) {
                $this->merge([$nullable => null]);
            }
        }
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
                // A work item can never be its own parent (§5).
                Rule::notIn([$item?->id]),
                Rule::exists('work_items', 'id')->where('project_id', $projectId),
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
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => [
                'integer',
                Rule::exists('project_item_labels', 'id')->where('project_id', $projectId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'due_date.after' => 'The due date must be after the start date.',
            'parent_id.not_in' => 'A work item cannot be its own parent.',
            'state_id.exists' => 'That state is not configured for this project.',
        ];
    }
}
