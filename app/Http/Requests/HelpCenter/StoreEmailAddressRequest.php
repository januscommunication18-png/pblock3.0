<?php

namespace App\Http\Requests\HelpCenter;

use App\Services\HelpCenter\EmailAddressGuard;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Adding one customer-facing address to an existing Inbox
 * (docs/features/help-center.md §6, §7).
 *
 * The wizard collects addresses alongside the Inbox (see StoreInboxRequest); this is the other
 * door — the Inboxes screen, adding one to an Inbox that already exists. Both go through
 * EmailAddressGuard, so both answer §7 the same way.
 */
class StoreEmailAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $workspace = Auth::user()?->currentWorkspace;
            $email = (string) $this->input('email');

            if ($email === '' || $validator->errors()->has('email')) {
                return;
            }

            if (! EmailAddressGuard::isRoutable($email)) {
                $validator->errors()->add('email', 'Enter a valid email address.');

                return;
            }

            if ($workspace === null) {
                return;
            }

            if (app(EmailAddressGuard::class)->isTaken($workspace, $email)) {
                $validator->errors()->add('email', EmailAddressGuard::TAKEN_MESSAGE);
            }
        });
    }

    /** Trim and lowercase before anything else looks at it (§7). */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'name' => $this->input('name') === null ? null : trim((string) $this->input('name')),
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => 'Enter an email address.',
            'email.email' => 'Enter a valid email address.',
        ];
    }
}
