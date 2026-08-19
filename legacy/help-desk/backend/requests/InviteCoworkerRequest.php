<?php

namespace App\Http\Requests\HelpDesk;

use App\Models\HelpDeskMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inviting a coworker who is not in the workspace yet (FR-1.5).
 *
 * Shape only. Whether that email can actually be invited — already a member, already invited,
 * out of seats — is the workspace invitation flow's question, answered per row and reported
 * back as a status the screen shows (see HelpDeskInviter).
 */
class InviteCoworkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:190'],
            'role' => ['required', 'string', Rule::in(HelpDeskMember::roles())],
            'inbox_ids' => ['sometimes', 'array'],
            'inbox_ids.*' => ['integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
    }
}
