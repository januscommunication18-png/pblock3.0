<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update a customer custom property (spec §11 / SET-CUST-003..005). Title + type
 * required; Dropdown carries an options list. Mandatory/Active are optional flags.
 */
class CustomerPropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->input('title')),
            'mandatory' => $this->boolean('mandatory'),
            'active' => $this->has('active') ? $this->boolean('active') : true,
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(config('settings.customer_property_types'))],
            'mandatory' => ['boolean'],
            'active' => ['boolean'],
            'options' => ['nullable', 'array', 'required_if:type,Dropdown'],
            'options.*' => ['string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return ['options.required_if' => 'Add at least one option for a Dropdown property.'];
    }
}
