<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** Update wiki description + docs link (spec §7). */
class WikiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'wiki_description' => ['nullable', 'string', 'max:1000'],
            'wiki_docs_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
