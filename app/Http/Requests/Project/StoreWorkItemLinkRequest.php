<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add or edit an external link (Collaboration spec §38/§39).
 *
 * The scheme allowlist is the point of this class: `url` alone would accept
 * `javascript:alert(1)`, which becomes a stored, clickable script the moment someone renders
 * the link. http and https only — everything else is refused.
 */
class StoreWorkItemLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'url' => trim((string) $this->input('url')),
            'title' => trim((string) $this->input('title')) ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'url.url' => 'Enter a valid link starting with http:// or https://.',
        ];
    }
}
