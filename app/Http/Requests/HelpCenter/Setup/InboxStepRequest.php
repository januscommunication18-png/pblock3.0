<?php

namespace App\Http\Requests\HelpCenter\Setup;

use App\Services\HelpCenter\EmailAddressGuard;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Step 3 — Set Up Your Inbox (docs/features/help-center.md, P2 §9, §10).
 *
 * P2 §9: "Keep the current Inbox Setup fields and functionality already implemented." These are
 * Phase 1's rules unchanged — the only difference is that nothing is written yet (HC-D11), so
 * there is no Inbox to check a name against. The Space does not exist either, which is why the
 * per-Space name uniqueness of §5 has nothing to compare with: this is the first Inbox.
 */
class InboxStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],

            // An Inbox with no addresses yet is legitimate — §6's section is how you connect
            // one, not a precondition for having one.
            'addresses' => ['present', 'array', 'max:25'],
            'addresses.*.email' => ['required', 'string', 'max:255'],
            'addresses.*.name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $workspace = Auth::user()?->currentWorkspace;
            $guard = app(EmailAddressGuard::class);
            $seen = [];

            foreach ((array) $this->input('addresses', []) as $i => $row) {
                $email = mb_strtolower(trim((string) ($row['email'] ?? '')));

                if ($email === '') {
                    continue;
                }

                if (! EmailAddressGuard::isRoutable($email)) {
                    $validator->errors()->add("addresses.$i.email", 'Enter a valid email address.');

                    continue;
                }

                if (isset($seen[$email])) {
                    $validator->errors()->add("addresses.$i.email", 'This address is already in the list.');

                    continue;
                }

                $seen[$email] = true;

                // §7's rule, against the Inboxes that already exist in this workspace.
                if ($workspace !== null && $guard->isTaken($workspace, $email)) {
                    $validator->errors()->add("addresses.$i.email", EmailAddressGuard::TAKEN_MESSAGE);
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $addresses = $this->input('addresses');

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'addresses' => is_array($addresses)
                ? array_values(array_map(fn ($row) => [
                    'email' => mb_strtolower(trim((string) ((array) $row)['email'] ?? '')),
                    'name' => trim((string) (((array) $row)['name'] ?? '')),
                ], $addresses))
                : [],
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the Inbox a name.',
            'name.max' => 'An Inbox name can be at most 100 characters.',
            'addresses.*.email.required' => 'Enter an email address.',
        ];
    }
}
