<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * Base for project-settings section controllers. Managing project settings requires project
 * admin OR workspace Owner/Admin (ProjectPolicy@manage). Section pages render a thin Blade
 * shell that mounts the section's Vue component with `bootstrap` data; mutations return JSON.
 */
abstract class ManagesProjectController extends Controller
{
    /** Owner/admin (workspace) or project-admin gate. 403 otherwise. */
    protected function guardManage(Project $project): void
    {
        abort_unless(Auth::user()->can('manage', $project), 403);
    }

    /** Next append position for an ordered project-scoped list model. */
    protected function nextPosition(string $modelClass, int $projectId): int
    {
        return (int) $modelClass::query()->where('project_id', $projectId)->max('position') + 1;
    }

    /** Render a project-settings section page. */
    protected function page(Project $project, string $section, array $bootstrap = []): View
    {
        return view("projects.settings.{$section}", [
            'workspace' => Auth::user()->currentWorkspace,
            'user' => Auth::user(),
            'project' => $project,
            'section' => $section,
            // Titles and the loading placeholder read this rather than ucfirst($section):
            // the key is a URL segment, not a name to show people.
            'sectionLabel' => collect(config('projects.settings_nav'))->firstWhere('key', $section)['label'] ?? ucfirst($section),
            'nav' => config('projects.settings_nav'),
            'bootstrap' => $bootstrap,
        ]);
    }
}
