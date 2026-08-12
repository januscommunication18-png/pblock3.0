<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectPage;
use App\Services\RichTextSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create Page validation (Pages §8).
 *
 * Authorization is HERE rather than in the controller: Laravel runs `authorize()` before the
 * rules, so someone without permission is refused outright instead of first being told which
 * of their fields were invalid.
 *
 * §8 says the project is inherited from the current one and the user should not pick it. That
 * is enforced by omission — there is no `project_id` rule, so a payload carrying one is
 * ignored; the controller takes the project from the URL.
 */
class StorePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [ProjectPage::class, $this->route('project')]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->input('title')),
            // §10's editor emits HTML, which is sanitized before it is validated or stored —
            // so the markup that reaches other people's browsers is always the cleaned one.
            'content' => $this->has('content')
                ? app(RichTextSanitizer::class)->sanitize($this->input('content'))
                : null,
            'parent_id' => $this->filled('parent_id') ? $this->input('parent_id') : null,
            // §9: a new page is a draft — documentation is written before it is ready to read.
            'status' => $this->filled('status') ? $this->input('status') : config('projects.page_default_status'),
        ]);
    }

    public function rules(): array
    {
        $projectId = $this->route('project')?->id;

        return [
            // §9: Draft or Published, nothing else — the vocabulary is config, so a
            // third state is a config change rather than a migration.
            'status' => ['required', Rule::in(array_keys(config('projects.page_statuses')))],
            'title' => ['required', 'string', 'max:'.config('projects.page_title_max')],
            'content' => ['nullable', 'string', 'max:'.config('projects.page_content_max')],
            // §9/§14: a parent from THIS project only, so a crafted payload cannot file a page
            // under another project's documentation.
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('project_pages', 'id')->where('project_id', $projectId)->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Give the page a title.',
            'parent_id.exists' => 'That page does not belong to this project.',
        ];
    }
}
