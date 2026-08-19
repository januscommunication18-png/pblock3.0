<?php

namespace App\Http\Requests\HelpDesk;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Connecting a customer-facing address to an inbox (Inbound Email requirements §5, §9).
 *
 * Only two fields, and only one of them is typed. The generated inbound address the page
 * displays is not here at all — it is not something the browser sends, so it is not something a
 * request can change (§2, "generated inbound addresses cannot be manually edited").
 *
 * `help_desk_inbox_id` is checked against THIS Help Desk in the controller rather than by a rule
 * here, where the Help Desk is not known: `exists` on its own would accept another workspace's
 * inbox id, which is the one mistake this field can make that matters.
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
            /*
             * `email` rather than a looser string: this address is what an arriving message is
             * matched against to decide whether somebody's forwarding works (§7). A value that
             * is not an address can never match anything, so it would sit at "setup required"
             * for ever with nothing on the screen explaining why.
             */
            'address' => ['required', 'string', 'email', 'max:190'],

            'help_desk_inbox_id' => ['nullable', 'integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            // Case and surrounding whitespace are not part of an address. Normalized once here
            // so the stored value, the duplicate check and the match on arrival all agree.
            'address' => strtolower(trim((string) $this->input('address'))),
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'address.required' => 'Enter the email address your customers write to.',
            'address.email' => 'That does not look like an email address.',
        ];
    }
}
