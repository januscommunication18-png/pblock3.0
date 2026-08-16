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
            // Which apps the workspace starts with (wiki WIKI-D1). Validated against what is
            // actually RELEASED, so an unreleased app cannot be switched on by editing the
            // form — the same guard `view_type` already has.
            'apps' => ['sometimes', 'array'],
            'apps.*' => [Rule::in(self::availableApps())],
        ];
    }

    /** App keys that can actually be enabled today. */
    protected static function availableApps(): array
    {
        return collect(config('workspace.apps'))
            ->filter(fn ($app) => ($app['available'] ?? false) === true)
            ->keys()
            ->all();
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
            'apps' => $this->chosenApps(),
        ];
    }

    /**
     * The apps to switch on, always including the defaults.
     *
     * Projects is not optional (WIKI-D3) and the form renders it locked, so it is added back
     * here rather than trusted from the request — a workspace that can do nothing is not a
     * choice worth offering.
     *
     * @return array<int, string>
     */
    protected function chosenApps(): array
    {
        $chosen = array_intersect((array) $this->validated('apps', []), self::availableApps());

        return array_values(array_unique(array_merge(config('workspace.default_apps', []), $chosen)));
    }
}
