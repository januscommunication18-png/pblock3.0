<?php

namespace App\Http\Requests\Workspace;

use App\Services\TenantSubdomain;
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

            /*
             * The tenant's customer-facing subdomain (P72).
             *
             * REQUIRED only when a customer-facing app is switched on, which is what the form
             * mirrors — the field appears with Help Center or Client Hub and not before.
             * `required_if` cannot express "this array contains one of these", so the presence
             * rule is applied in withValidator() where the chosen apps can actually be read.
             *
             * Everything else comes from `TenantSubdomain::rules()`, so the live availability
             * check as somebody types and the check on submit are the same rule rather than two
             * that agree until one of them is edited.
             */
            'subdomain' => array_merge(['nullable'], TenantSubdomain::rules()),
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

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /*
             * A customer-facing workspace must have a host (P72).
             *
             * Checked on the APPS rather than on a hidden flag from the form, so switching Help
             * Center on and leaving the field blank is refused by the same rule whichever screen
             * submitted it — and a hand-rolled request cannot skip it by omitting the field.
             */
            if (! TenantSubdomain::requiredFor($this->chosenApps())) {
                return;
            }

            if (trim((string) $this->input('subdomain')) === '') {
                $validator->errors()->add(
                    'subdomain',
                    'Choose a subdomain for your customer-facing pages.',
                );
            }
        });
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

        /*
         * Normalised BEFORE the rules see it (P72).
         *
         * `Acme Inc` becomes `acme-inc` and `HTTPS://ACME.projectblock.app/` becomes `acme`, so
         * the rules run against — and the column stores — the string that will actually be a
         * hostname. Validating what was typed and storing something else is how a name the user
         * was told was available turns into a different one in the database.
         */
        $this->merge([
            'name' => $name,
            'slug' => $slug,
            'subdomain' => TenantSubdomain::normalize($this->input('subdomain')),
        ]);
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The URL may only contain lowercase letters, numbers and hyphens.',
            'slug.not_in' => 'That URL is reserved. Please choose another.',
            'slug.unique' => 'That URL is already taken. Please choose another.',
            'subdomain.regex' => 'A subdomain may only contain lowercase letters, numbers and hyphens.',
            'subdomain.not_in' => 'That subdomain is reserved. Please choose another.',
            'subdomain.not_regex' => 'That subdomain is reserved. Please choose another.',
            'subdomain.unique' => 'That subdomain is already taken. Please choose another.',
            'subdomain.min' => 'A subdomain must be at least :min characters.',
            'subdomain.max' => 'A subdomain can be at most :max characters.',
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
            /*
             * NULL rather than '' when there is none (P72).
             *
             * The column is unique, and repeated empty strings would collide with each other —
             * so the second workspace created without a customer-facing app would be refused by
             * the database for sharing a subdomain nobody has. Repeated NULLs do not collide,
             * which is the whole reason the column is nullable.
             */
            'subdomain' => ($this->validated('subdomain') ?: null),
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
