<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectView;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a View (Views §6).
 *
 * The visibility rule is the interesting one: §4.2 lets a project switch *Allow Project Views*
 * and *Allow Private Views* off independently, so which options exist is a per-project
 * question, answered here rather than trusted from the client.
 */
class StoreProjectViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project !== null && $this->user()->can('create', [ProjectView::class, $project]);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.config('projects.view_name_max')],
            // §6.2: Work Items is the only dataset in Phase 1. Validated against a list rather
            // than hard-coded so Phase 3's Epic/Module/Cycle datasets are an addition here.
            'dataset_type' => ['sometimes', Rule::in([ProjectView::DATASET_WORK_ITEMS])],
            'visibility' => ['required', Rule::in($this->allowedVisibilities())],
            'density' => ['sometimes', Rule::in(array_keys(config('projects.view_densities')))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'visibility.in' => 'That visibility is turned off for this project.',
        ];
    }

    /**
     * The visibilities this project allows right now (§4.2).
     *
     * If a project somehow allows neither, creating a View is refused rather than defaulted —
     * silently picking one would hand the user a View more visible than the project permits.
     *
     * @return array<int, string>
     */
    private function allowedVisibilities(): array
    {
        $project = $this->route('project');
        $allowed = [];

        if ($project?->featureEnabled('view_private') ?? true) {
            $allowed[] = ProjectView::VISIBILITY_PRIVATE;
        }

        if ($project?->featureEnabled('view_project') ?? true) {
            $allowed[] = ProjectView::VISIBILITY_PROJECT;
        }

        return $allowed;
    }
}
