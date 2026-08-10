<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change a member's role (spec §5 / SET-M-008). Only the four assignable roles are valid —
 * Owner is never assignable through settings (owner transfer is a separate, out-of-scope
 * flow). Last-owner/last-admin safeguards are enforced in the controller.
 */
class UpdateMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(config('workspace.invite_roles'))],
        ];
    }
}
