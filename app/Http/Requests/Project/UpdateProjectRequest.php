<?php

namespace App\Http\Requests\Project;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Project General settings update (PRJ-040). Same rules as create, but the identifier
 * uniqueness ignores the current project and the timezone is editable.
 */
class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'identifier' => strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $this->input('identifier'))),
        ]);
    }

    public function rules(): array
    {
        $workspaceId = $this->user()->current_workspace_id;

        return [
            'name' => ['required', 'string', 'max:120'],
            // The identifier is FIXED once the project exists: it is baked into every work
            // item identifier, every link anyone has shared, and every reference outside the
            // app. The field is read-only in the UI; this refuses a changed value outright
            // rather than ignoring it, so an attempt to change it is answered rather than
            // silently dropped.
            'identifier' => ['sometimes', 'string', function ($attribute, $value, $fail) {
                $current = (string) $this->route('project')?->identifier;
                if (strtolower((string) $value) !== strtolower($current)) {
                    $fail('The identifier cannot be changed after the project is created.');
                }
            }],
            'description' => ['nullable', 'string', 'max:2000'],
            // Keys, not labels: `visibilities` is a key => label map, so validating against
            // the map itself compared the submitted value with "Public"/"Private" and
            // rejected every real save. Matches StoreProjectRequest.
            'visibility' => ['required', Rule::in(array_keys(config('projects.visibilities')))],
            'lead_user_id' => [
                'nullable', 'integer',
                Rule::exists('workspace_memberships', 'user_id')
                    ->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('status', 'active')),
            ],
            'timezone' => ['nullable', 'string', 'timezone:all'],
            // §10: who can see which work items in this project.
            'work_item_view' => ['sometimes', Rule::in([Project::VIEW_ALL, Project::VIEW_ASSIGNED])],
            // §12/§13: both are limited to active members of THIS workspace, so a settings
            // save cannot quietly attach someone from another tenant.
            'default_assignee_id' => [
                'nullable', 'integer',
                Rule::exists('workspace_memberships', 'user_id')
                    ->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('status', 'active')),
            ],
            'subscriber_ids' => ['sometimes', 'array', 'max:100'],
            'subscriber_ids.*' => [
                'integer',
                Rule::exists('workspace_memberships', 'user_id')
                    ->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('status', 'active')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
        ];
    }
}
