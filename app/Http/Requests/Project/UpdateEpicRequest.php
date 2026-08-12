<?php

namespace App\Http\Requests\Project;

use App\Models\Epic;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit Epic validation (Epic §7).
 *
 * Every field is `sometimes`, so the list's inline controls can PATCH one property — a status
 * chip, a priority, a lead — without resending the whole epic. Authorization runs first, for
 * the same reason as StoreEpicRequest.
 */
class UpdateEpicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('epic'));
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('title')) {
            $merge['title'] = trim((string) $this->input('title'));
        }
        if ($this->has('description')) {
            $merge['description'] = $this->filled('description') ? trim((string) $this->input('description')) : null;
        }
        // A cleared picker posts "", which means "remove it" rather than "leave it alone".
        foreach (['start_date', 'target_date', 'lead_user_id'] as $nullable) {
            if ($this->has($nullable)) {
                $merge[$nullable] = $this->filled($nullable) ? $this->input($nullable) : null;
            }
        }
        if ($this->has('member_ids')) {
            $merge['member_ids'] = array_values(array_unique(array_filter(
                (array) $this->input('member_ids', []),
                fn ($id) => $id !== null && $id !== '',
            )));
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        $shared = StoreEpicRequest::sharedRules($this->user()->current_workspace_id);

        // `sometimes` on every shared rule: an absent field is untouched, a present one is
        // held to exactly the same standard it was created under.
        $rules = [];
        foreach ($shared as $field => $rule) {
            $rules[$field] = array_merge(['sometimes'], (array) $rule);
        }

        $rules['title'] = ['sometimes', 'required', 'string', 'max:'.config('projects.epic_title_max')];

        // The date pair is checked against what is ALREADY stored when only one half is sent —
        // otherwise moving a start date past a target date would pass, because `after_or_equal`
        // has no other date in the payload to compare with.
        $epic = $this->route('epic');
        if ($epic instanceof Epic && ! $this->has('target_date') && $this->has('start_date') && $epic->target_date) {
            $rules['start_date'][] = 'before_or_equal:'.$epic->target_date->format('Y-m-d');
        }
        if ($epic instanceof Epic && ! $this->has('start_date') && $this->has('target_date') && $epic->start_date) {
            $rules['target_date'][] = 'after_or_equal:'.$epic->start_date->format('Y-m-d');
        }

        return $rules;
    }

    public function messages(): array
    {
        return array_merge(StoreEpicRequest::sharedMessages(), [
            'title.required' => 'Give the epic a title.',
            'start_date.before_or_equal' => 'The start date cannot be later than the target date.',
        ]);
    }
}
