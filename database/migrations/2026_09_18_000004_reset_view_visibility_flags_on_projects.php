<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;

/**
 * Unstick the Views visibility sub-settings (Views §4.2).
 *
 * `view_project` and `view_private` default to ON — they are permissions the Views feature
 * grants, not extras a user opts into. But they arrived as `requires => views` sub-features,
 * and `ProjectSettingsController::toggleFeature` used to force every dependent to `false` when
 * its prerequisite was switched off. Views defaults to off, so any project that toggled it
 * ended up with BOTH visibilities stored as false — Views switched on, and no visibility a new
 * view could legally use, so the create dialog offered nothing and the server refused every
 * value it was sent.
 *
 * The cascade is fixed (it now clears only dependents that default OFF). This clears the wrong
 * values it already wrote, by REMOVING the keys rather than setting them true: `featureFlags()`
 * merges catalog defaults for anything absent, so removing the key is what restores the default
 * — and it does not overwrite a project that deliberately turned one off after the fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Project::withoutGlobalScopes()
            ->whereNotNull('features')
            ->cursor()
            ->each(function (Project $project) {
                $features = is_array($project->features) ? $project->features : [];

                // Only where the cascade could have written them: both false at once is the
                // signature of the bug, since nothing in the UI offers that combination.
                if (($features['view_project'] ?? null) !== false || ($features['view_private'] ?? null) !== false) {
                    return;
                }

                unset($features['view_project'], $features['view_private']);

                $project->forceFill(['features' => $features])->saveQuietly();
            });
    }

    public function down(): void
    {
        // Nothing to undo: the keys were absent before, and absent is what they are again.
    }
};
