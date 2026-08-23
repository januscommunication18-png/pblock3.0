<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Models\HelpCenterSpace;
use App\Models\Workspace;
use App\Services\WorkspaceApps;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The link a Space can be reached by from OUTSIDE the app — `/help-center/go/spaces/{space}`
 * (docs/features/help-center.md, P10).
 *
 * Every other Help Center URL runs behind `workspace.tenancy`, which resolves the Space out of
 * whichever workspace the visitor happens to have active. That is right for navigation, where
 * you are already somewhere, and wrong for a link in an email: it is opened days later, from a
 * mail client, by somebody who may belong to three workspaces and have a different one active —
 * and the tenant scope then simply cannot see Space 11, so the invitation email's button
 * answered 404. Worse, it answers 404 to the very person who was just added to it.
 *
 * So this route deliberately looks the Space up WITHOUT tenancy, checks membership of the
 * workspace that owns it, makes that workspace current, and only then hands over to the
 * ordinary tenant-scoped screen. Signed-out visitors never reach it: `auth` sends them to
 * sign-in with this URL as the return target (AppServiceProvider), which is what makes
 * "log in first, then land in the Space" work without the email having to know either step.
 *
 * Non-membership is a 404, not a 403 — a workspace someone does not belong to should not be
 * confirmed to exist by the error it returns, the same rule GuardsHelpCenter applies.
 */
class SpaceEntryController extends Controller
{
    /** GET /help-center/go/spaces/{space} */
    public function __invoke(int $space): RedirectResponse
    {
        $user = Auth::user();

        /** @var HelpCenterSpace|null $model */
        $model = HelpCenterSpace::query()->withoutTenancy()->whereKey($space)->first();

        abort_if($model === null, 404);

        $workspace = Workspace::query()->whereKey($model->tenant_id)->first();

        abort_unless(
            $workspace !== null
                && $user->can('view', $workspace)
                && app(WorkspaceApps::class)->isEnabled($workspace, 'helpdesk'),
            404,
        );

        // Only when it differs — a needless write on every click would touch the users row
        // each time somebody follows a link to the workspace they are already in.
        if ((string) $user->current_workspace_id !== (string) $workspace->id) {
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        }

        return redirect()->route('help-center.spaces.section', [
            'space' => $model->id,
            'section' => 'overview',
        ]);
    }
}
