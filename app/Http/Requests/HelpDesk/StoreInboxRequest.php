<?php

namespace App\Http\Requests\HelpDesk;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating or renaming an inbox (docs/features/help-desk.md, FR-1.7).
 *
 * A name is the whole of an inbox in Phase 1. Uniqueness is enforced by the database (unique
 * per Help Desk) and turned into a message by the controller, rather than validated here with
 * a rule that would need the Help Desk id and could still lose a race.
 */
class StoreInboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
        ];
    }
}
