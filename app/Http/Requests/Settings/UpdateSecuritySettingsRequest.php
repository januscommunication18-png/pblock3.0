<?php

namespace App\Http\Requests\Settings;

use App\Services\SessionTimeout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Settings > Security (SES-002).
 *
 * The whitelist is enforced here, not only offered in the dropdown — a value the UI never
 * shows is exactly the value a crafted request will send.
 */
class UpdateSecuritySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller's guardManage() is the gate; this request is reachable only through it.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'session_timeout_minutes' => [
                'required',
                'integer',
                Rule::in(app(SessionTimeout::class)->options()),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'session_timeout_minutes.in' => 'Choose one of the available session timeout values.',
        ];
    }
}
