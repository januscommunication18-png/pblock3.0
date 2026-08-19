<?php

namespace App\Http\Requests\HelpDesk;

use App\Models\HelpDeskMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changing a Help Desk member's role, status or inbox access (FR-1.6/1.7/1.8).
 *
 * All three fields are optional and applied independently, so the screen can change one thing
 * without resending the other two — and so a request that omits `inbox_ids` never reads as
 * "take every inbox away".
 */
class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', 'string', Rule::in(HelpDeskMember::roles())],
            'status' => ['sometimes', 'string', Rule::in([
                HelpDeskMember::STATUS_ACTIVE,
                HelpDeskMember::STATUS_INACTIVE,
            ])],
            'inbox_ids' => ['sometimes', 'array'],
            'inbox_ids.*' => ['integer'],
        ];
    }
}
