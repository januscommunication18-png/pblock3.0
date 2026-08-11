<?php

namespace App\Http\Requests\Project;

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
            'identifier' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $this->input('identifier'))),
        ]);
    }

    public function rules(): array
    {
        $workspaceId = $this->user()->current_workspace_id;
        $projectId = $this->route('project')?->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'identifier' => [
                'required', 'string', 'max:10', 'regex:/^[A-Z0-9]{1,10}$/',
                Rule::notIn(config('projects.reserved_identifiers')),
                Rule::unique('projects', 'identifier')
                    ->where(fn ($q) => $q->where('tenant_id', $workspaceId))
                    ->ignore($projectId),
            ],
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
        ];
    }

    public function messages(): array
    {
        return [
            'identifier.regex' => 'The ID may only contain uppercase letters and numbers.',
            'identifier.unique' => 'That project ID is already used in this workspace.',
        ];
    }
}
