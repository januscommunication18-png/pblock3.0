<?php

namespace App\Http\Requests\Draft;

use App\Services\RichTextSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit Draft validation (docs/features/drafts.md §"Business Rules").
 *
 * The same four fields as StoreDraftRequest, each `sometimes` — the editor saves the one
 * control that changed rather than the whole form. Authorization is the draft's own: only its
 * author may edit it, and the controller has already 404'd anyone else.
 */
class UpdateDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateDraft', $this->route('draft'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('title')) {
            $this->merge(['title' => trim((string) $this->input('title'))]);
        }
        // Rich text from <wi-editor> — sanitized before validation, as StoreDraftRequest does.
        if ($this->has('description')) {
            $this->merge(['description' => app(RichTextSanitizer::class)->sanitize($this->input('description'))]);
        }
        foreach (['start_date', 'due_date'] as $date) {
            if ($this->has($date) && ! $this->filled($date)) {
                $this->merge([$date => null]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:'.config('projects.work_item_title_max')],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.config('projects.work_item_description_max')],
            'priority' => ['sometimes', 'required', Rule::in(array_keys(config('projects.work_item_priorities')))],
            'start_date' => ['sometimes', 'nullable', 'date', ...$this->orderRule('due_date', 'before')],
            'due_date' => ['sometimes', 'nullable', 'date', ...$this->orderRule('start_date', 'after')],
        ];
    }

    /**
     * Keep start < due across a partial update.
     *
     * `after:start_date` compares against a *field*, and the editor saves one control at a
     * time — a PATCH that only moves the due date carries no start date for it to read, so the
     * check would silently pass. When the counterpart is absent from the payload the draft's
     * stored value is used as a literal instead, which is the value the rule is actually about.
     *
     * @return array<int, string>
     */
    private function orderRule(string $counterpart, string $rule): array
    {
        if ($this->has($counterpart)) {
            return $this->filled($counterpart) ? ["{$rule}:{$counterpart}"] : [];
        }

        $stored = $this->route('draft')?->{$counterpart};

        return $stored ? ["{$rule}:{$stored->toDateString()}"] : [];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Give the draft a title.',
            'due_date.after' => 'The due date must be after the start date.',
            'start_date.before' => 'The start date must be before the due date.',
        ];
    }
}
