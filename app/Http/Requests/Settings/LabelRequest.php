<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared create/update validation for the color labels used across Projects, Wiki,
 * Releases and Initiatives (spec §6-§9). Color accepts any valid #RRGGBB hex; the UI also
 * offers a curated preset palette (spec §13).
 */
class LabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    public function messages(): array
    {
        return ['color.regex' => 'Choose a preset color or enter a valid hex value (e.g. #F97316).'];
    }
}
