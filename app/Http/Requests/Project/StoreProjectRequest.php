<?php

namespace App\Http\Requests\Project;

use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Create Project validation (spec §4.2 / §7, PRJ-021..027). Authorization is enforced in
 * the controller via ProjectPolicy@create; this request only normalizes + validates.
 *
 * Identifier uniqueness is workspace-scoped (PRJ-022) and validated server-side even if the
 * client already passed (spec §7) — the DB UNIQUE(tenant_id, identifier) constraint backs
 * it against races. Identifier is auto-derived from the name when the user left it blank
 * (PRJ-023), using the same uppercase-alphanumeric rule as the prototype.
 */
class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $name = trim((string) $this->input('name'));

        // Lower case, strip non-alphanumerics, cap length. Derive from the name when the
        // identifier field is empty; otherwise normalize what the user typed.
        $max = (int) config('projects.identifier_max');
        $identifier = $this->normalizeIdentifier((string) $this->input('identifier'), $max);
        if ($identifier === '') {
            $identifier = $this->normalizeIdentifier($name, $max);
        }

        $this->merge([
            'name' => $name,
            'identifier' => $identifier,
            // Blank lead select should be null, not the empty string.
            'lead_user_id' => $this->filled('lead_user_id') ? $this->input('lead_user_id') : null,
        ]);
    }

    public function rules(): array
    {
        $workspaceId = $this->user()->current_workspace_id;

        return [
            'name' => ['required', 'string', 'max:'.config('projects.name_max')],
            'identifier' => [
                'required', 'string', 'max:'.config('projects.identifier_max'),
                'regex:/^[a-z0-9]+$/', // lower-case alphanumeric
                // PRJ-022: unique within THIS workspace only (same code may exist elsewhere).
                Rule::unique('projects', 'identifier')->where('tenant_id', $workspaceId),
            ],
            'description' => ['nullable', 'string', 'max:'.config('projects.description_max')],
            'visibility' => ['required', Rule::in(array_keys(config('projects.visibilities')))],
            // PRJ-026: a lead must be an active member of the current workspace.
            'lead_user_id' => [
                'nullable', 'integer',
                Rule::exists('workspace_memberships', 'user_id')
                    ->where('workspace_id', $workspaceId)
                    ->where('status', WorkspaceMembership::STATUS_ACTIVE),
            ],
            // PRJ-027: the gradient picked instead of an upload. Restricted to the palette,
            // not free text — it is rendered into a `background:` style on every card.
            'cover_gradient' => ['nullable', 'string', Rule::in(
                config('projects.cover_presets') ?? config('projects.cover_gradients') ?? []
            )],
            // PRJ-027: optional cover; validated type/size, recoverable error otherwise.
            'cover' => [
                'nullable', 'image',
                'mimes:'.implode(',', config('projects.cover.mimes')),
                'max:'.config('projects.cover.max_kb'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'identifier.regex' => 'The identifier may only contain lowercase letters and numbers.',
            'identifier.unique' => 'That identifier is already used in this workspace. Please choose another.',
            'lead_user_id.exists' => 'Choose a lead from the current workspace members.',
            'cover.image' => 'The cover must be an image file.',
            'cover.mimes' => 'Use a JPG, PNG, WEBP or GIF image for the cover.',
            'cover.max' => 'The cover image is too large.',
        ];
    }

    /** Normalized project attributes (excludes the cover file — handled by the service). */
    public function projectData(): array
    {
        return [
            'name' => $this->validated('name'),
            'identifier' => $this->validated('identifier'),
            'description' => $this->validated('description'),
            'visibility' => $this->validated('visibility'),
            'lead_user_id' => $this->validated('lead_user_id'),
            'cover_gradient' => $this->validated('cover_gradient'),
        ];
    }

    private function normalizeIdentifier(string $value, int $max): string
    {
        return Str::substr(preg_replace('/[^a-z0-9]/', '', Str::lower($value)), 0, $max);
    }
}
