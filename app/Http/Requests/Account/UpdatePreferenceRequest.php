<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The Preference tab's Language & Time (Account §2).
 *
 * These are WORKSPACE settings reached from a personal-looking menu, so the gate is the same
 * one Workspace Settings uses — `manageSettings`, which is owner/admin. Authorising here as
 * well as in the controller is deliberate: a form request that validates a payload it is not
 * allowed to submit is a 422 where a 403 belongs, and the two answers tell a caller different
 * things about what they may do.
 */
class UpdatePreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = Auth::user()?->currentWorkspace;

        return $workspace !== null && Auth::user()->can('manageSettings', $workspace);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $days = array_keys(config('workspace.week_days'));

        return [
            // The same IANA identifier Settings → General stores, validated the same way.
            'timezone' => ['required', 'string', 'timezone'],
            // Only a language that actually has a pack. A "coming soon" entry is shown by the
            // picker but must not be storable, or the UI would be in a language that is not there.
            'language' => ['required', 'string', Rule::in($this->availableLanguages())],
            'first_day_of_week' => ['required', 'integer', Rule::in($days)],
            'weekend_days' => ['present', 'array'],
            'weekend_days.*' => ['integer', Rule::in($days)],
        ];
    }

    /** @return array<int, string> */
    private function availableLanguages(): array
    {
        return collect(config('workspace.languages', []))
            ->filter(fn (array $l) => $l['available'] ?? false)
            ->pluck('value')
            ->all();
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('weekend_days')) {
            return;
        }

        // A weekend can legitimately be empty — a workspace that works every day — so the field
        // is `present` rather than `required`, and duplicates are collapsed here rather than
        // rejected: sending Saturday twice is a client mistake, not a user error worth a message.
        $this->merge([
            'weekend_days' => array_values(array_unique(array_map('intval', (array) $this->input('weekend_days')))),
        ]);
    }
}
