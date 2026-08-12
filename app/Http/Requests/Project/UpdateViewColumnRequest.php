<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A column's own settings (Views §10): alias, width, visibility, editability.
 *
 * Position and order are NOT here — they arrive through the layout endpoint, because a drag
 * changes several columns at once and only makes sense applied together (§8.3).
 */
class UpdateViewColumnRequest extends FormRequest
{
    public function authorize(): bool
    {
        $view = $this->route('view');
        $column = $this->route('column');

        // The column must belong to the View in the URL: a column id is not a capability.
        return $view !== null
            && $column !== null
            && (int) $column->project_view_id === (int) $view->id
            && $this->user()->can('update', $view);
    }

    protected function prepareForValidation(): void
    {
        // An empty alias means "go back to the catalog's label", which is stored as null
        // rather than as an empty string so the two cannot drift apart.
        if ($this->has('display_name') && trim((string) $this->input('display_name')) === '') {
            $this->merge(['display_name' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Bounded so a drag cannot persist a column that is one pixel wide or wider than
            // any screen — either of which leaves a View the user cannot fix from the grid.
            'width' => ['sometimes', 'nullable', 'integer', 'min:60', 'max:900'],
            'is_visible' => ['sometimes', 'boolean'],
            // A ceiling only (§11.3): true cannot make a read-only field editable, and the
            // server re-checks on every cell write regardless of what is stored here.
            'is_editable' => ['sometimes', 'boolean'],
        ];
    }
}
