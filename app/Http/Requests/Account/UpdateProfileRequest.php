<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Profile tab's save (Account §1).
 *
 * Note what is NOT here: the email address. The form shows it read-only and this request does
 * not accept it, because changing the address someone signs in with is an identity change —
 * it needs verification of the new address and notice to the old one, which is a different
 * feature from editing a profile. Accepting the field "because the form displays it" is how
 * a read-only input becomes an account takeover.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is the authorization: it only ever edits the signed-in user's own row.
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'display_name' => ['nullable', 'string', 'max:120'],
            /*
             * The agent's Help Center signature (P74).
             *
             * Plain text — the column stores what was typed and the HTML is built on render, so
             * there is nothing to sanitize here beyond a length. 2000 characters is a generous
             * sign-off and a firm ceiling: this string is appended to every reply the agent
             * sends, so an unbounded one is an unbounded email.
             */
            'signature' => ['nullable', 'string', 'max:2000'],
            // Chosen from the palette, not typed. Anything off the list is refused rather than
            // trusted: this string is rendered into a `style` attribute, and a free-text value
            // there is a CSS injection with the user's own profile as the vector.
            'cover_gradient' => ['nullable', 'string', Rule::in(config('projects.cover_gradients', []))],
        ];
    }

    /** Blank inputs mean "cleared", not "the empty string". */
    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (['first_name', 'last_name', 'display_name', 'signature'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = trim((string) $this->input($field));
            $trimmed[$field] = $value === '' ? null : $value;
        }

        $this->merge($trimmed);
    }
}
