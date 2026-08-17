<?php

namespace App\Http\Requests\Settings;

use App\Services\WorkspaceApps;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * General settings update (spec §4 / SET-G-002..005). Authorization is enforced in the
 * controller via WorkspacePolicy@manageSettings. Slug uniqueness excludes the current
 * workspace so re-saving an unchanged slug is allowed.
 */
class UpdateWorkspaceGeneralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'slug' => Str::slug((string) $this->input('slug')),
        ]);
    }

    public function rules(): array
    {
        $workspaceId = $this->user()->current_workspace_id;

        return [
            'name' => ['required', 'string', 'max:80'],
            'company_size' => ['required', Rule::in(config('workspace.team_sizes'))],
            'slug' => [
                'required', 'string', 'max:60',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(config('workspace.reserved_slugs')),
                Rule::unique('tenants', 'slug')->ignore($workspaceId, 'id'),
            ],
            'timezone' => ['required', 'string', 'timezone:all'],

            // Which apps the workspace subscribes to. Validated against what is released AND
            // actually optional, so neither an unreleased app nor Projects can be changed here.
            'apps' => ['sometimes', 'array'],
            'apps.*' => ['string', Rule::in(app(WorkspaceApps::class)->selectable())],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The URL may only contain lowercase letters, numbers and hyphens.',
            'slug.not_in' => 'That URL is reserved. Please choose another.',
            'slug.unique' => 'That URL is already taken. Please choose another.',
        ];
    }
}
