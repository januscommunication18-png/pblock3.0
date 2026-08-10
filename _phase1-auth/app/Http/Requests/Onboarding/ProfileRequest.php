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
            'full_name'             => ['required', 'string', 'max:255'],
            'set_password'          => ['nullable', 'boolean'],
            'password'              => ['nullable', 'required_if:set_password,1', 'confirmed', Password::defaults()],
            'marketing_opt_in'      => ['nullable', 'boolean'],
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
