<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Change password (Account §4).
 *
 * `current_password` is required only when there is one to be current. A user who signed up
 * with a login code or through an identity provider has no password credential row at all
 * (D-A2), and demanding the old one would be demanding something that has never existed —
 * locking the only people who need this form out of it.
 */
class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Acts on the signed-in user and takes no id, so there is nothing here to authorise
        // beyond being signed in.
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => [$this->user()->hasPassword() ? 'required' : 'nullable', 'string'],
            // `confirmed` pairs this with `password_confirmation`, which is what the Confirm
            // field posts. Password::defaults() is the same strength rule onboarding applies,
            // so a password that was acceptable at signup stays acceptable here.
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Enter your current password.',
            'password.confirmed' => 'The two new passwords do not match.',
        ];
    }
}
