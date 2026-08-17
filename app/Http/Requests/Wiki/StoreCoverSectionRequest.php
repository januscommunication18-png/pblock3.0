<?php

namespace App\Http\Requests\Wiki;

use App\Models\WikiCoverSection;
use App\Support\IconRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One Cover Page section card (docs/features/wiki-cover-page.md, FR-WC-004 … FR-WC-012).
 *
 * The destination is checked against THIS collection by the controller, which is the only place
 * that knows which one is being edited.
 */
class StoreCoverSectionRequest extends FormRequest
{
    /** Authorization is the collection's, and the controller holds it. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['title', 'description'] as $field) {
            if (! is_string($this->input($field))) {
                continue;
            }

            $value = trim($this->input($field));
            $this->merge([$field => $value === '' ? null : $value]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:200'],

            'visual_type' => ['required', Rule::in(WikiCoverSection::visualTypes())],

            /*
             * Required only when the card is wearing one, and it has to be an icon that exists.
             * An unknown name renders nothing in production — a typo would ship as an invisible
             * card rather than an error somebody could see and fix.
             */
            'icon_key' => [
                $this->input('visual_type') === WikiCoverSection::VISUAL_ICON ? 'required' : 'nullable',
                'string', 'max:60',
                Rule::in(array_keys(IconRegistry::all())),
            ],

            'destination_type' => ['required', Rule::in(WikiCoverSection::destinationTypes())],
            'destination_id' => ['required', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'icon_key.required' => 'Choose an icon, or set the visual to None.',
            'icon_key.in' => 'That icon is not one this application can draw.',
        ];
    }
}
