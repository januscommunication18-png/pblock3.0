<?php

namespace App\Http\Requests\Project;

use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit Project validation — backs the card's Edit modal (PRJ-045), which saves the same
 * fields the create modal collects plus the four card chips (status, priority, start/end
 * date) in one request.
 *
 * Mirrors StoreProjectRequest's normalization so the two modals behave identically; the
 * identifier uniqueness check ignores the project being edited. Authorization is enforced
 * in the controller (manage rights), never here.
 */
class UpdateProjectDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            // The modal posts "" for every cleared picker; store those as NULL.
            'lead_user_id' => $this->filled('lead_user_id') ? $this->input('lead_user_id') : null,
            'state_id' => $this->filled('state_id') ? $this->input('state_id') : null,
            'priority_id' => $this->filled('priority_id') ? $this->input('priority_id') : null,
            'start_date' => $this->filled('start_date') ? $this->input('start_date') : null,
            'end_date' => $this->filled('end_date') ? $this->input('end_date') : null,
        ]);
    }

    public function rules(): array
    {
        $workspaceId = $this->user()->current_workspace_id;

        // NOTE: `identifier` is deliberately absent. The identifier is the @mention handle
        // work items are referenced by, so it is immutable once created — the modal renders
        // it read-only and the controller never writes it, whatever the client posts.
        return [
            'name' => ['required', 'string', 'max:'.config('projects.name_max')],
            'description' => ['nullable', 'string', 'max:'.config('projects.description_max')],
            'visibility' => ['required', Rule::in(array_keys(config('projects.visibilities')))],
            // PRJ-026: a lead must be an active member of the current workspace.
            'lead_user_id' => [
                'nullable', 'integer',
                Rule::exists('workspace_memberships', 'user_id')
                    ->where('workspace_id', $workspaceId)
                    ->where('status', WorkspaceMembership::STATUS_ACTIVE),
            ],
            // Existence of the status/priority is checked against the workspace's own
            // configured sets in the controller, which already memoizes both maps.
            'state_id' => ['nullable', 'integer'],
            'priority_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'lead_user_id.exists' => 'Choose a lead from the current workspace members.',
            'end_date.after_or_equal' => 'The due date must be on or after the start date.',
        ];
    }
}
