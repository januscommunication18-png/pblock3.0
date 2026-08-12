<?php

namespace App\Http\Requests\Project;

use App\Services\RichTextSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit Page validation (Pages §12).
 *
 * Every field is `sometimes`, so the editor can save the body alone without resending the
 * title — a document editor autosaves one thing at a time.
 */
class UpdatePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('page'));
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('title')) {
            $merge['title'] = trim((string) $this->input('title'));
        }
        if ($this->has('content')) {
            $merge['content'] = app(RichTextSanitizer::class)->sanitize($this->input('content'));
        }
        if ($this->has('parent_id')) {
            $merge['parent_id'] = $this->filled('parent_id') ? $this->input('parent_id') : null;
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        $projectId = $this->route('project')?->id;
        $page = $this->route('page');

        return [
            // §9: Draft or Published, nothing else — the vocabulary is config, so a
            // third state is a config change rather than a migration.
            'status' => ['sometimes', 'required', Rule::in(array_keys(config('projects.page_statuses')))],
            'title' => ['sometimes', 'required', 'string', 'max:'.config('projects.page_title_max')],
            'content' => ['sometimes', 'nullable', 'string', 'max:'.config('projects.page_content_max')],
            'parent_id' => [
                'sometimes', 'nullable', 'integer',
                // A page cannot be its own parent. Deeper cycles are unreachable while the UI
                // does not nest; when §14's hierarchy lands, this is where the walk goes.
                Rule::notIn([$page?->id]),
                Rule::exists('project_pages', 'id')->where('project_id', $projectId)->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Give the page a title.',
            'parent_id.not_in' => 'A page cannot be its own parent.',
        ];
    }
}
