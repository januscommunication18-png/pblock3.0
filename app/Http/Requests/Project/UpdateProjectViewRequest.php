<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectView;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Renaming a View, or changing its visibility or density (Views §5.3, §12.5).
 *
 * Every field is `sometimes` so the toolbar's density control can send density alone without
 * restating the name — the same shape the work item chips use.
 */
class UpdateProjectViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $view = $this->route('view');

        return $view !== null && $this->user()->can('update', $view);
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
            'name' => ['sometimes', 'required', 'string', 'max:'.config('projects.view_name_max')],
            'visibility' => ['sometimes', Rule::in($this->allowedVisibilities())],
            'density' => ['sometimes', Rule::in(array_keys(config('projects.view_densities')))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['visibility.in' => 'That visibility is turned off for this project.'];
    }

    /**
     * As on create — but a View's CURRENT visibility always stays allowed.
     *
     * Turning *Allow Private Views* off must not make an existing private View unsaveable:
     * §9.3's instinct is that a setting change never breaks what is already stored, and a
     * rename failing because of a visibility the user did not touch would be exactly that.
     *
     * @return array<int, string>
     */
    private function allowedVisibilities(): array
    {
        $view = $this->route('view');
        $project = $view?->project;
        $allowed = [];

        if ($project?->featureEnabled('view_private')) {
            $allowed[] = ProjectView::VISIBILITY_PRIVATE;
        }

        if ($project?->featureEnabled('view_project')) {
            $allowed[] = ProjectView::VISIBILITY_PROJECT;
        }

        if ($view && ! in_array($view->visibility, $allowed, true)) {
            $allowed[] = $view->visibility;
        }

        return $allowed;
    }
}
