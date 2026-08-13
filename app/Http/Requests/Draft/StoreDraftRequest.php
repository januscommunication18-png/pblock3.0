<?php

namespace App\Http\Requests\Draft;

use App\Models\WorkItem;
use App\Services\RichTextSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create Draft validation (docs/features/drafts.md §"Business Rules").
 *
 * Short by design. A draft holds a title and a description and nothing else: state, labels,
 * assignees, parent,
 * cycle, module, epic and estimate are all validated against a project (see
 * StoreWorkItemRequest, where every one of them is scoped by `project_id`), and a draft has no
 * project to validate them against — so it does not accept them at all (D-D3). `validated()`
 * returns only the five free-text fields below, which is what keeps a crafted payload carrying
 * `project_id` or `state_id` from reaching the insert (DR-14).
 */
class StoreDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createDraft', WorkItem::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->input('title')),
            // Rich text from <wi-editor> (D-D4) — sanitized before it is validated or stored,
            // so the markup that survives is the cleaned version and never what was posted.
            // The same call the work item detail's description goes through.
            'description' => app(RichTextSanitizer::class)->sanitize($this->input('description')),
            'priority' => $this->filled('priority') ? $this->input('priority') : WorkItem::PRIORITY_NONE,
            'start_date' => $this->filled('start_date') ? $this->input('start_date') : null,
            'due_date' => $this->filled('due_date') ? $this->input('due_date') : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.config('projects.work_item_title_max')],
            'description' => ['nullable', 'string', 'max:'.config('projects.work_item_description_max')],
            'priority' => ['required', Rule::in(array_keys(config('projects.work_item_priorities')))],
            'start_date' => ['nullable', 'date'],
            // Same rule as a real work item's: strictly after, and a due date on its own stays
            // valid. Checked now rather than at publish, so a draft can never fail to publish
            // because of dates chosen days earlier.
            'due_date' => ['nullable', 'date', 'after:start_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Give the draft a title.',
            'due_date.after' => 'The due date must be after the start date.',
        ];
    }
}
