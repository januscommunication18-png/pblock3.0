<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Validation\Rule;

/**
 * Additional workspace creation (spec §7). This form includes "Choose your view":
 * Agile is selectable; Classic is Coming Soon. Only currently-available views validate,
 * and the value must be one whose config flag is available (WS-VIEW-002/003).
 */
class StoreWorkspaceRequest extends WorkspaceFormRequest
{
    public function rules(): array
    {
        return $this->baseRules() + [
            'view_type' => ['required', Rule::in($this->availableViews())],
        ];
    }

    public function workspaceData(): array
    {
        return parent::workspaceData() + [
            'view_type' => $this->validated('view_type'),
        ];
    }

    /** View keys that are actually creatable right now (Classic excluded until released). */
    private function availableViews(): array
    {
        return collect(config('workspace.views'))
            ->filter(fn ($v) => $v['available'] === true)
            ->keys()
            ->all();
    }

    public function messages(): array
    {
        return parent::messages() + [
            'view_type.in' => 'That workspace view is not available yet.',
        ];
    }
}
