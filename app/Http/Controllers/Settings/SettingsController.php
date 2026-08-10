<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Models\WorkspaceSettings;
use App\Services\WorkspaceSettingsManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * Base for every Workspace Settings section controller.
 *
 * Tenancy is already initialized to the current workspace by the InitializeWorkspaceTenancy
 * middleware, so tenant-scoped models resolve directly. Managing any settings section
 * requires owner/admin (WorkspacePolicy@manageSettings) — enforced by guardManage(). Section
 * pages render a thin Blade shell that mounts the section's Vue component with `bootstrap`
 * data; mutations return JSON.
 */
abstract class SettingsController extends Controller
{
    public function __construct(protected WorkspaceSettingsManager $settingsManager) {}

    /** The active workspace (tenant) for this request. */
    protected function workspace(): Workspace
    {
        return Auth::user()->currentWorkspace;
    }

    /** Owner/admin gate for managing settings (spec §3 / §13). 403 otherwise. */
    protected function guardManage(): void
    {
        abort_unless(Auth::user()->can('manageSettings', $this->workspace()), 403);
    }

    /** The workspace settings singleton (provisioned + seeded on first access). */
    protected function settings(): WorkspaceSettings
    {
        return $this->settingsManager->for($this->workspace());
    }

    /** Render a settings section page: settings shell + nav + mounted Vue component. */
    protected function page(string $section, array $bootstrap = []): View
    {
        return view("settings.{$section}", [
            'workspace' => $this->workspace(),
            'user' => Auth::user(),
            'section' => $section,
            'nav' => config('settings.nav'),
            'bootstrap' => $bootstrap,
        ]);
    }

    /** Set a boolean feature toggle on the workspace settings singleton. */
    protected function setFeature(string $field, bool $enabled): WorkspaceSettings
    {
        $settings = $this->settings();
        $settings->forceFill([$field => $enabled])->save();

        return $settings;
    }

    /** Next append position for an ordered tenant-scoped list model. */
    protected function nextPosition(string $modelClass): int
    {
        return (int) $modelClass::query()->max('position') + 1;
    }

    /**
     * Curated palette + hex support metadata shared by every color picker (spec §13).
     *
     * @return array<string, mixed>
     */
    protected function colorMeta(): array
    {
        return ['presets' => config('settings.color_presets')];
    }
}
