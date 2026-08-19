<?php

namespace App\Http\Requests\HelpDesk;

use App\Models\HelpDeskMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding an existing workspace member to the Help Desk (docs/features/help-desk.md, FR-1.4).
 *
 * Shape only. WHO may be added (an active workspace member, not already a member) and WHO may
 * add them are decided by HelpDeskMemberManager and the policy — rules that the invite path in
 * slice 3 has to obey too, and that would drift if they were written out here as well.
 */
class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is the controller's, against the Help Desk this request resolves to.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', 'string', Rule::in(HelpDeskMember::roles())],

            // Inbox access (FR-1.7). Absent means none named — which is what a role that
            // reaches every inbox needs, and what a restricted member starts with.
            'inbox_ids' => ['sometimes', 'array'],
            'inbox_ids.*' => ['integer'],
        ];
    }
}
