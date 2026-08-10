<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A name, not an email: no "@", and it must contain at least one letter.
            'full_name'             => ['required', 'string', 'min:2', 'max:255', 'not_regex:/@/', 'regex:/\p{L}/u'],
            'set_password'          => ['nullable', 'boolean'],
            'password'              => ['nullable', 'required_if:set_password,1', 'confirmed', Password::defaults()],
            'marketing_opt_in'      => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.not_regex' => 'Please enter your name, not an email address.',
            'full_name.regex'     => 'Please enter a valid name.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'full_name'        => trim((string) $this->input('full_name')),
            'set_password'     => $this->boolean('set_password'),
            'marketing_opt_in' => $this->boolean('marketing_opt_in'),
        ]);
    }
}
