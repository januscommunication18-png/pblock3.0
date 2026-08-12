<?php

namespace App\Http\Requests\Project;

/**
 * Edit Module (Module Management §11) — "validation rules from creation must also apply
 * during editing", so this extends the create request rather than restating them. A rule
 * added to create can never silently go missing on edit.
 */
class UpdateModuleRequest extends StoreModuleRequest
{
    public function authorize(): bool
    {
        $module = $this->route('module');

        return $module !== null && $this->user()->can('update', $module);
    }
}
