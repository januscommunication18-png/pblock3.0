<?php

namespace App\Http\Requests\Draft;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Publish Draft validation (docs/features/drafts.md §5, DR-08/DR-10).
 *
 * The one moment a draft acquires a project, so this is where the project-scoped checks
 * StoreWorkItemRequest applies at creation finally have something to run against: the project
 * must be one the user may add work to, and the starting state must be that project's own.
 *
 * `project` is resolved here rather than trusted from the payload — `authorize()` needs the
 * model to ask the policy about it, and a project id from another workspace never resolves,
 * because the Project query runs inside the active workspace's tenancy context.
 */
class PublishDraftRequest extends FormRequest
{
    private ?Project $project = null;

    public function authorize(): bool
    {
        $project = $this->project();

        // No project, or one outside this workspace: refused as unauthorized rather than as a
        // validation error, so the response never distinguishes "does not exist" from
        // "not yours".
        return $project !== null
            && $this->user()->can('publishDraft', [$this->route('draft'), $project]);
    }

    protected function prepareForValidation(): void
    {
        // The modal posts "" for a project with no states configured, and an empty string is
        // not an integer — normalized here rather than left to the empty-string middleware,
        // the same way StoreWorkItemRequest handles its untouched pickers.
        $this->merge(['state_id' => $this->filled('state_id') ? $this->input('state_id') : null]);
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer'],
            // Optional: a draft can be published straight into the project's default state by
            // leaving this out. Scoped to the project so a crafted payload cannot start the
            // item in another project's state.
            'state_id' => [
                'nullable', 'integer',
                Rule::exists('project_item_states', 'id')->where('project_id', $this->project()?->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.required' => 'Choose a project to publish into.',
            'state_id.exists' => 'That state is not configured for this project.',
        ];
    }

    /** The target project, or null if the payload names one that is not in this workspace. */
    public function project(): ?Project
    {
        return $this->project ??= Project::find($this->input('project_id'));
    }
}
