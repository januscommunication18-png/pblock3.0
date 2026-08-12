<?php

namespace App\Http\Requests\Project;

use App\Models\Module;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create Module validation (Module Management §5.2/§15).
 *
 * Authorization is HERE rather than in the controller: Laravel runs `authorize()` before the
 * rules, so someone without permission is refused outright instead of first being told which
 * of their fields were invalid.
 */
class StoreModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Module::class, $this->route('project')]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            // §15: a title of nothing but whitespace is not a title.
            'title' => trim((string) $this->input('title')),
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,
            'status' => $this->filled('status') ? $this->input('status') : config('projects.module_default_status'),
            'start_date' => $this->filled('start_date') ? $this->input('start_date') : null,
            'end_date' => $this->filled('end_date') ? $this->input('end_date') : null,
            'lead_user_id' => $this->filled('lead_user_id') ? $this->input('lead_user_id') : null,
            'member_ids' => array_values(array_unique(array_filter(
                (array) $this->input('member_ids', []),
                fn ($id) => $id !== null && $id !== '',
            ))),
        ]);
    }

    public function rules(): array
    {
        $workspaceId = $this->user()->current_workspace_id;

        // §15: the lead and every member must be an eligible member of this workspace, so a
        // crafted payload cannot attach someone from another tenant.
        $member = [
            'integer',
            Rule::exists('workspace_memberships', 'user_id')
                ->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('status', WorkspaceMembership::STATUS_ACTIVE)),
        ];

        return [
            'title' => ['required', 'string', 'max:'.config('projects.module_title_max')],
            'description' => ['nullable', 'string', 'max:'.config('projects.module_description_max')],
            // §5.2: the six lifecycle states, defaulting to Backlog.
            'status' => ['required', Rule::in(array_keys(config('projects.module_statuses')))],
            // §5.2/§6.1: both optional — a Backlog module may have no schedule at all.
            'start_date' => ['nullable', 'date'],
            // §15: equal is fine; a module can start and end on the same day.
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'lead_user_id' => array_merge(['nullable'], $member),
            'member_ids' => ['array', 'max:100'],
            'member_ids.*' => $member,
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Give the module a title.',
            'end_date.after_or_equal' => 'The end date cannot be earlier than the start date.',
            'lead_user_id.exists' => 'The lead must be an active member of this workspace.',
            'member_ids.*.exists' => 'Members must be active members of this workspace.',
        ];
    }
}
