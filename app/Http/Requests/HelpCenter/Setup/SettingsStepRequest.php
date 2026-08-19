<?php

namespace App\Http\Requests\HelpCenter\Setup;

use App\Models\HelpCenterSpaceSettings;
use App\Services\HelpCenter\EmailAddressGuard;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 5 — Conversation Settings & Metadata
 * (docs/features/help-center.md, P2 §17–§24; §29).
 *
 * Every rule here is conditional on a toggle, which is the whole shape of this step: an address
 * only has to be valid if Auto BCC is on, and a duration only has to be non-zero if
 * reassignment is on. Validating them unconditionally would make somebody fill in fields for a
 * feature they just switched off.
 */
class SettingsStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'metadata' => ['present', 'array'],
            'metadata.*' => ['boolean'],

            'auto_bcc_enabled' => ['boolean'],
            'auto_bcc_email' => ['nullable', 'string', 'max:255'],

            'reassign_enabled' => ['boolean'],
            'reassign_hours' => ['integer', 'min:0', 'max:720'],
            // 0-59: sixty minutes is an hour, and the pair would otherwise accept "2h 90m"
            // (P2 §21).
            'reassign_minutes' => ['integer', 'min:0', 'max:59'],
            'reassign_destination' => ['required', Rule::in(HelpCenterSpaceSettings::destinations())],

            'auto_follow_mentions' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /*
             * AI Tag is "Coming Soon" and cannot be enabled (P2 §18, HC-D18).
             *
             * Refused here rather than only disabled in the UI, because "coming soon" is a
             * statement about the feature and not about the button. Anything config marks
             * unavailable is treated the same way, so the next Coming Soon toggle needs no code.
             */
            foreach ((array) $this->input('metadata', []) as $key => $on) {
                $meta = config("help-center.metadata.$key");

                if ($meta === null) {
                    $validator->errors()->add("metadata.$key", 'Unknown setting.');

                    continue;
                }

                if ($on && ! ($meta['available'] ?? false)) {
                    $validator->errors()->add(
                        "metadata.$key",
                        ($meta['label'] ?? 'This setting').' is coming soon and cannot be enabled yet.',
                    );
                }
            }

            // Auto BCC (P2 §19).
            if ($this->boolean('auto_bcc_enabled')) {
                $email = mb_strtolower(trim((string) $this->input('auto_bcc_email')));

                if ($email === '') {
                    $validator->errors()->add('auto_bcc_email', 'Enter the address to BCC.');
                } elseif (! EmailAddressGuard::isRoutable($email)) {
                    $validator->errors()->add('auto_bcc_email', 'Enter a valid email address.');
                }
            }

            /*
             * Reassignment (P2 §21): "the total duration must be greater than zero when
             * reassignment is enabled". Checked on the TOTAL, not on either field — 0h 30m and
             * 2h 0m are both fine, and only 0h 0m is not.
             */
            if ($this->boolean('reassign_enabled')) {
                $total = ((int) $this->input('reassign_hours', 0) * 60) + (int) $this->input('reassign_minutes', 0);

                if ($total <= 0) {
                    $validator->errors()->add('reassign_hours', 'Set how long to wait before reassigning.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'metadata' => is_array($this->input('metadata')) ? $this->input('metadata') : [],
            'auto_bcc_enabled' => $this->boolean('auto_bcc_enabled'),
            'auto_bcc_email' => mb_strtolower(trim((string) $this->input('auto_bcc_email'))),
            'reassign_enabled' => $this->boolean('reassign_enabled'),
            'reassign_hours' => (int) $this->input('reassign_hours', 0),
            'reassign_minutes' => (int) $this->input('reassign_minutes', 0),
            'reassign_destination' => (string) $this->input(
                'reassign_destination',
                HelpCenterSpaceSettings::DESTINATION_UNASSIGNED,
            ),
            'auto_follow_mentions' => $this->boolean('auto_follow_mentions'),
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reassign_minutes.max' => 'Minutes must be between 0 and 59.',
            'reassign_destination.in' => 'Choose where the conversation should go.',
        ];
    }
}
