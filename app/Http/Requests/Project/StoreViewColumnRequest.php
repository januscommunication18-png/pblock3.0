<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectViewColumn;
use App\Services\ViewFieldCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding a column to a View (Views §9).
 *
 * Two refusals live here, and they are different refusals on purpose:
 *
 *  - **§9.2** — a field whose feature is off cannot be ADDED. Note the asymmetry with §9.3: a
 *    column already saved for that field is kept and marked unavailable. Disabling a feature
 *    preserves; adding under a disabled feature is prevented.
 *  - **§8.3** — a column can exist only once in a View. Caught here so the user gets a
 *    sentence rather than a unique-constraint violation.
 */
class StoreViewColumnRequest extends FormRequest
{
    public function authorize(): bool
    {
        $view = $this->route('view');

        return $view !== null && $this->user()->can('update', $view);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', Rule::in(array_keys(app(ViewFieldCatalog::class)->all()))],
            'position' => ['sometimes', Rule::in([ProjectViewColumn::POSITION_FIXED, ProjectViewColumn::POSITION_SCROLL])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['key.in' => 'That is not a field this view can show.'];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $view = $this->route('view');
                $key = (string) $this->input('key');

                if (! $view || ! $key) {
                    return;
                }

                $catalog = app(ViewFieldCatalog::class);
                $field = $catalog->find($key);

                if ($field === null) {
                    return;
                }

                if (! $catalog->isAvailable($view->project, $field)) {
                    $validator->errors()->add('key', $this->disabledMessage($field['feature']));

                    return;
                }

                [$source, $name] = explode('.', $key, 2);

                $exists = ProjectViewColumn::query()
                    ->where('project_view_id', $view->id)
                    ->where('source_type', $source)
                    ->where('source_field', $name)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('key', 'That column is already in this view.');
                }
            },
        ];
    }

    private function disabledMessage(?string $feature): string
    {
        $label = config("projects.features.{$feature}.label", $feature);

        return "{$label} are turned off for this project, so that field cannot be added. Enable {$label} in Project Settings.";
    }
}
