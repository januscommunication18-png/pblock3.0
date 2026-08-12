<?php

namespace App\Http\Requests\Project;

use App\Models\Epic;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create Epic validation (Epic §6).
 *
 * Authorization is HERE rather than in the controller: Laravel runs `authorize()` before the
 * rules, so someone without permission is refused outright instead of first being told which
 * of their fields were invalid.
 *
 * §6 also says the Project is assigned automatically and cannot be changed from within the
 * form. That is enforced by omission — there is no `project_id` rule, so a payload carrying
 * one is ignored rather than honoured; the controller takes the project from the URL.
 */
class StoreEpicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Epic::class, $this->route('project')]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            // §6: a title of nothing but whitespace is not a title.
            'title' => trim((string) $this->input('title')),
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,
            'status' => $this->filled('status') ? $this->input('status') : config('projects.epic_default_status'),
            'priority' => $this->filled('priority') ? $this->input('priority') : Epic::PRIORITY_NONE,
            'start_date' => $this->filled('start_date') ? $this->input('start_date') : null,
            'target_date' => $this->filled('target_date') ? $this->input('target_date') : null,
            'lead_user_id' => $this->filled('lead_user_id') ? $this->input('lead_user_id') : null,
            'member_ids' => array_values(array_unique(array_filter(
                (array) $this->input('member_ids', []),
                fn ($id) => $id !== null && $id !== '',
            ))),
        ]);
    }

    public function rules(): array
    {
        return array_merge(self::sharedRules($this->user()->current_workspace_id), [
            'title' => ['required', 'string', 'max:'.config('projects.epic_title_max')],
        ]);
    }

    /**
     * The rules Create and Edit share. Kept in one place because §6's validation is about the
     * Epic itself, and an epic that could be edited into a shape it could never be created in
     * would be a hole rather than a convenience.
     *
     * @return array<string, mixed>
     */
    public static function sharedRules(?string $workspaceId): array
    {
        // §6: the lead and every member must be an eligible member of this workspace, so a
        // crafted payload cannot attach someone from another tenant.
        $member = [
            'integer',
            Rule::exists('workspace_memberships', 'user_id')
                ->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('status', WorkspaceMembership::STATUS_ACTIVE)),
        ];

        return [
            'description' => ['nullable', 'string', 'max:'.config('projects.epic_description_max')],
            // §5: the six lifecycle values, defaulting to Backlog.
            'status' => ['required', Rule::in(array_keys(config('projects.epic_statuses')))],
            // §5: the same fixed priority vocabulary work items use, so the two read alike.
            'priority' => ['required', Rule::in(array_keys(config('projects.work_item_priorities')))],
            // §5: both optional — a Backlog epic may have no schedule at all.
            'start_date' => ['nullable', 'date'],
            // §6: "Target Date cannot be earlier than Start Date" — equal is therefore fine.
            'target_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'lead_user_id' => array_merge(['nullable'], $member),
            'member_ids' => ['array', 'max:100'],
            'member_ids.*' => $member,
        ];
    }

    public static function sharedMessages(): array
    {
        return [
            'target_date.after_or_equal' => 'The target date cannot be earlier than the start date.',
            'lead_user_id.exists' => 'The lead must be an active member of this workspace.',
            'member_ids.*.exists' => 'Members must be active members of this workspace.',
        ];
    }

    public function messages(): array
    {
        return array_merge(self::sharedMessages(), [
            'title.required' => 'Give the epic a title.',
        ]);
    }
}
