<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Teammate invitations submitted from the onboarding invite step (spec §6).
 *
 * Validation is intentionally lenient: per the spec, invalid/duplicate emails are handled
 * per-row without discarding the valid rows, so email quality is classified by
 * WorkspaceInviter rather than hard-failing the whole submission here. Only the role is
 * constrained to the allowed invite roles (Owner is never invitable).
 */
class StoreInvitesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invites' => ['nullable', 'array', 'max:100'],
            'invites.*.email' => ['nullable', 'string', 'max:255'],
            'invites.*.role' => ['nullable', Rule::in(config('workspace.invite_roles'))],
        ];
    }

    /**
     * @return array<int, array{email:string, role:string}>
     */
    public function inviteRows(): array
    {
        return collect($this->input('invites', []))
            ->map(fn ($row) => [
                'email' => (string) ($row['email'] ?? ''),
                'role' => (string) ($row['role'] ?? ''),
            ])
            ->all();
    }
}
