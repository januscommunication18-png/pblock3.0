<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Shared normalization + validation for the two workspace-creation forms
 * (first-workspace onboarding and additional create). Keeps slug/name/team-size
 * rules in one place (spec §4, WS-001..WS-006).
 */
abstract class WorkspaceFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Name + slug + team size rules common to both create flows. */
    protected function baseRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'], // WS-001
            'slug' => [
                'required', 'string', 'max:60',
                // WS-004: lowercase letters, numbers, hyphens; no leading/trailing/double hyphens.
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(config('workspace.reserved_slugs')),
                Rule::unique('tenants', 'slug'), // WS-005: DB unique constraint backs this
            ],
            'team_size' => ['required', Rule::in(config('workspace.team_sizes'))], // WS-006
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = trim((string) $this->input('name'));

        // Auto-generate the slug from the name when the user hasn't provided one (WS-002),
        // and always normalize what was submitted.
        $slug = Str::slug((string) $this->input('slug'));
        if ($slug === '') {
            $slug = Str::slug($name);
        }

        $this->merge([
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The URL may only contain lowercase letters, numbers and hyphens.',
            'slug.not_in' => 'That URL is reserved. Please choose another.',
            'slug.unique' => 'That URL is already taken. Please choose another.',
        ];
    }

    /** Normalized workspace attributes for the creator service. */
    public function workspaceData(): array
    {
        return [
            'name' => $this->validated('name'),
            'slug' => $this->validated('slug'),
            'company_size' => $this->validated('team_size'),
        ];
    }
}
