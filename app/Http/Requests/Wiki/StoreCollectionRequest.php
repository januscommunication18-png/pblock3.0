<?php

namespace App\Http\Requests\Wiki;

use App\Models\WikiCollection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a collection (docs/features/wiki.md).
 */
class StoreCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller holds the gate; this is only reachable through it.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['required', Rule::in([
                WikiCollection::VISIBILITY_PUBLIC,
                WikiCollection::VISIBILITY_PRIVATE,
            ])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.required' => 'Give the collection a name.'];
    }
}
