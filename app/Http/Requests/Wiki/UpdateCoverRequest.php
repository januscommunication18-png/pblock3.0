<?php

namespace App\Http\Requests\Wiki;

use App\Models\WikiCover;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Cover Page settings panel (docs/features/wiki-cover-page.md).
 *
 * The whole panel is submitted every time, including by the enable switch — so "may this be
 * turned on?" can be answered against the title in front of the person turning it on, rather
 * than against whatever was last saved.
 */
class UpdateCoverRequest extends FormRequest
{
    /** Authorization is the collection's, and the controller holds it. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trimmed on the way in, and blank becomes null.
     *
     * A title of three spaces passes `required` and renders as an empty heading — the one
     * outcome WCOV-2 exists to prevent.
     */
    protected function prepareForValidation(): void
    {
        foreach (['title', 'short_description'] as $field) {
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
            'is_enabled' => ['required', 'boolean'],

            // WCOV-2 — an enabled cover with no heading is a blank page where the front door
            // should be. Required only when it is being turned on, so a disabled cover can be
            // emptied and kept.
            'title' => [$this->boolean('is_enabled') ? 'required' : 'nullable', 'string', 'max:100'],
            'short_description' => ['nullable', 'string', 'max:300'],

            'global_search_enabled' => ['required', 'boolean'],
            'previous_next_enabled' => ['required', 'boolean'],
            'on_this_page_enabled' => ['required', 'boolean'],

            'content_alignment' => ['required', Rule::in(WikiCover::alignments())],
            'card_layout' => ['required', Rule::in(WikiCover::layouts())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Give the cover a title before turning it on.',
        ];
    }
}
