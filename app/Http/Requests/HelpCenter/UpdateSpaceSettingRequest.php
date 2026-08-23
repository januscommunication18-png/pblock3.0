<?php

namespace App\Http\Requests\HelpCenter;

use App\Models\HelpCenterSpace;
use App\Models\HelpCenterSpaceSettings;
use App\Services\HelpCenter\EmailAddressGuard;
use App\Services\HelpCenter\WorkflowStatusPayload;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One Space Settings panel's save (docs/features/help-center.md, P11).
 *
 * The rules are chosen by the SECTION in the URL, not by what the request happens to contain.
 * That is the point: each panel owns a few fields, and validating the union of all of them
 * would let the Tag page — a single switch — post a reassignment threshold. `validated()`
 * then returns only that section's keys, so the controller writes only what the panel renders.
 *
 * Deliberately the same rules `Setup\SettingsStepRequest` applies at step 5, because they
 * describe the same fields. Where a rule is subtle enough to be worth explaining twice, it is
 * explained here too rather than left as a cross-reference somebody has to go and read.
 */
class UpdateSpaceSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is the Space policy's answer, and the controller asks it. A request
        // class that re-derived it would be a second place for that rule to live.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->kind()) {
            // Six metadata switches and auto-follow are all one boolean, so they are one rule.
            'metadata', 'auto_follow' => [
                'enabled' => ['required', 'boolean'],
            ],

            /*
             * Company & Customer (P75 §2) — the master switch and its four sub-switches, sent
             * together as one map.
             *
             * One payload rather than five requests: they are drawn as one group of switches on
             * one page, and saving them one at a time would leave the master on with its
             * sub-switches unsaved if the second call failed. Each key is optional, so a panel
             * that sends only what changed is still valid.
             */
            'company_customer' => collect(array_merge(['company'], HelpCenterSpace::COMPANY_SUB_FEATURES))
                ->mapWithKeys(fn (string $key) => ['features.'.$key => ['sometimes', 'boolean']])
                ->all() + ['features' => ['required', 'array']],

            'auto_bcc' => [
                'auto_bcc_enabled' => ['required', 'boolean'],
                // The WHOLE list, every time (P13). The panel owns all of it, so a partial
                // write would need the server to guess which of the two lists in front of it —
                // the one sent and the one stored — is the newer.
                'auto_bcc_emails' => ['array', 'max:'.HelpCenterSpaceSettings::bccMax()],
                // Shape only. Whether each is routable is checked in withValidator, so the
                // message can name the address that is wrong instead of the index it sits at.
                'auto_bcc_emails.*' => ['string', 'max:255'],
            ],

            // The status list, by the same rules the setup wizard's step 4 applies (P16).
            'workflow' => WorkflowStatusPayload::rules(),

            'reassignment' => [
                'reassign_enabled' => ['required', 'boolean'],
                'reassign_hours' => ['integer', 'min:0', 'max:720'],
                // 0-59: sixty minutes is an hour, and the pair would otherwise accept "2h 90m".
                'reassign_minutes' => ['integer', 'min:0', 'max:59'],
                'reassign_destination' => ['required', Rule::in(HelpCenterSpaceSettings::destinations())],
            ],

            /*
             * The Space's Inbound Email Display Name (P65) — the ONLY writable field on the
             * Inbox panel. The inbound address is generated and read-only, so it has no rule
             * here: a field with no rule is a field `validated()` will never hand the controller.
             */
            'inbox' => [
                'inbound_display_name' => ['nullable', 'string', 'max:100'],
            ],

            default => [],
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->kind() === 'metadata') {
                /*
                 * A Coming Soon toggle cannot be switched on (P2 §18, HC-D18).
                 *
                 * The nav does not link to it and the route 404s it, so reaching this line
                 * means a hand-rolled request. It is refused all the same: "coming soon" is a
                 * statement about the feature, not about the button.
                 */
                $meta = (array) config('help-center.metadata.'.$this->metadataKey());

                if ($this->boolean('enabled') && ! ($meta['available'] ?? false)) {
                    $validator->errors()->add(
                        'enabled',
                        ($meta['label'] ?? 'This setting').' is coming soon and cannot be enabled yet.',
                    );
                }
            }

            if ($this->kind() === 'company_customer') {
                $this->checkFeatures($validator);
            }

            if ($this->kind() === 'workflow') {
                WorkflowStatusPayload::check($validator, (array) $this->input('statuses', []));
            }

            if ($this->kind() === 'auto_bcc') {
                $emails = (array) $this->input('auto_bcc_emails', []);
                $seen = [];

                foreach ($emails as $email) {
                    $email = (string) $email;

                    if ($email === '' || ! EmailAddressGuard::isRoutable($email)) {
                        $validator->errors()->add('auto_bcc_emails', $email === ''
                            ? 'Enter the address to BCC.'
                            : $email.' is not a valid email address.');

                        continue;
                    }

                    // The same address twice is two copies of every message to one mailbox.
                    if (in_array($email, $seen, true)) {
                        $validator->errors()->add('auto_bcc_emails', $email.' is already on the list.');
                    }

                    $seen[] = $email;
                }

                /*
                 * Auto BCC may be ON with an empty list, and that is deliberate.
                 *
                 * The switch is what somebody flips FIRST — the list is what they fill in
                 * afterwards — so refusing an empty one would make the feature impossible to
                 * turn on. The panel says plainly that nothing is being copied yet; a Space in
                 * that state sends no blind copies, which is exactly what an empty list means.
                 */
            }

            /*
             * Checked on the TOTAL, not on either field — 0h 30m and 2h 0m are both fine, and
             * only 0h 0m is not. A per-field "greater than zero" would reject "0h 30m", which
             * is the most ordinary setting anybody would choose.
             */
            if ($this->kind() === 'reassignment' && $this->boolean('reassign_enabled')) {
                $total = ((int) $this->input('reassign_hours', 0) * 60) + (int) $this->input('reassign_minutes', 0);

                if ($total <= 0) {
                    $validator->errors()->add('reassign_hours', 'Set how long to wait before reassigning.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        match ($this->kind()) {
            'metadata', 'auto_follow' => $this->merge(['enabled' => $this->boolean('enabled')]),
            /*
             * Cast to real booleans, and keep only the keys this panel owns.
             *
             * JSON sends `true`, a form sends `"1"`, and the stored map has to hold one of them
             * — a metadata map holding `"0"` is a switch every reader has to remember is truthy.
             * The whitelist is what stops a hand-rolled request writing an arbitrary key into a
             * JSON column that has no schema to refuse it.
             */
            'company_customer' => $this->merge([
                'features' => collect((array) $this->input('features', []))
                    ->only(array_merge(['company'], HelpCenterSpace::COMPANY_SUB_FEATURES))
                    ->map(fn ($value) => filter_var($value, FILTER_VALIDATE_BOOL))
                    ->all(),
            ]),
            'workflow' => $this->merge([
                'statuses' => WorkflowStatusPayload::normalize($this->input('statuses')),
            ]),
            'auto_bcc' => $this->merge([
                'auto_bcc_enabled' => $this->boolean('auto_bcc_enabled'),
                // Normalised HERE, once, so the validator, the controller and the stored row
                // all see the same string — an address that differs only by case or by a
                // trailing space is the same mailbox, and storing both would send it two copies.
                'auto_bcc_emails' => collect((array) $this->input('auto_bcc_emails', []))
                    ->map(fn ($email) => mb_strtolower(trim((string) $email)))
                    ->all(),
            ]),
            'inbox' => $this->merge([
                'inbound_display_name' => trim((string) $this->input('inbound_display_name', '')),
            ]),
            'reassignment' => $this->merge([
                'reassign_enabled' => $this->boolean('reassign_enabled'),
                'reassign_hours' => (int) $this->input('reassign_hours', 0),
                'reassign_minutes' => (int) $this->input('reassign_minutes', 0),
                'reassign_destination' => (string) $this->input(
                    'reassign_destination',
                    HelpCenterSpaceSettings::DESTINATION_UNASSIGNED,
                ),
            ]),
            default => null,
        };
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return WorkflowStatusPayload::messages() + [
            'auto_bcc_emails.max' => 'Auto BCC can hold at most '.HelpCenterSpaceSettings::bccMax().' addresses.',
            'reassign_minutes.max' => 'Minutes must be between 0 and 59.',
            'reassign_destination.in' => 'Choose where the conversation should go.',
            'inbound_display_name.max' => 'A sender name can be at most 100 characters.',
        ];
    }

    /** What the section in the URL renders — and therefore what it may save. */
    private function kind(): string
    {
        return (string) ($this->navItem()['kind'] ?? '');
    }

    /**
     * The same Coming Soon rule the single-switch panel applies, over a map of switches.
     *
     * Reaching this means a hand-rolled request — none of the five is marked unavailable — and
     * it is refused anyway, because the rule is about the FEATURE and not about the button
     * (HC-D18). Cheap, and it stays correct the day one of them is marked Coming Soon.
     */
    private function checkFeatures(Validator $validator): void
    {
        foreach ((array) $this->input('features', []) as $key => $value) {
            $meta = (array) config('help-center.metadata.'.$key);

            if ($meta === []) {
                $validator->errors()->add('features', 'Unknown setting.');

                continue;
            }

            if ($value && ! ($meta['available'] ?? false)) {
                $validator->errors()->add(
                    'features.'.$key,
                    ($meta['label'] ?? 'This setting').' is coming soon and cannot be enabled yet.',
                );
            }
        }
    }

    /** Which of the metadata switches this section owns. */
    private function metadataKey(): string
    {
        return (string) ($this->navItem()['metadata'] ?? '');
    }

    /** @return array<string, mixed> */
    private function navItem(): array
    {
        return (array) collect((array) config('help-center.space_settings_nav'))
            ->firstWhere('key', (string) $this->route('setting'));
    }
}
