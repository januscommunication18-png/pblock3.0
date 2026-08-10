<?php

namespace App\Http\Requests\Workspace;

/**
 * First-workspace onboarding (spec §3 / WS-VIEW-004). The onboarding step does NOT ask
 * the user to choose a view — the workspace is created as Agile by default.
 */
class StoreOnboardingWorkspaceRequest extends WorkspaceFormRequest
{
    public function rules(): array
    {
        return $this->baseRules();
    }

    public function workspaceData(): array
    {
        return parent::workspaceData() + [
            'view_type' => config('workspace.default_view'), // always agile here
        ];
    }
}
