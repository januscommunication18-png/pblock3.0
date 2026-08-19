<?php

namespace App\Http\Controllers\HelpCenter\Concerns;

use App\Models\Workspace;
use App\Services\WorkspaceApps;
use Illuminate\Support\Facades\Auth;

/**
 * The one gate every Help Center screen passes through
 * (docs/features/help-center.md, "Enablement and access").
 *
 * A workspace that has not switched the app on has no Help Center to reach, so these 404 rather
 * than rendering an area its owner never enabled — the same rule routes/wiki.php applies, and
 * the same reason: an authorization error would confirm that the thing exists.
 *
 * Asked through WorkspaceApps so the rail, the create form, Settings → General and these
 * controllers cannot disagree about whether the app is on.
 */
trait GuardsHelpCenter
{
    /** The current workspace, or a 404 if the Help Center is not switched on for it. */
    protected function helpCenterWorkspace(): Workspace
    {
        $workspace = Auth::user()->currentWorkspace;

        abort_unless(
            $workspace !== null && app(WorkspaceApps::class)->isEnabled($workspace, 'helpdesk'),
            404,
        );

        return $workspace;
    }
}
