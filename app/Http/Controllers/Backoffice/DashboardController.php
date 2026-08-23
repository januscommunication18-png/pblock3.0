<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\BackofficeAuditLog;
use App\Models\BackofficeUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * The Back Office landing screen (docs/features/backoffice-auth.md).
 *
 * Deliberately thin. This build is the authentication and security boundary; the modules §12
 * lists — customers, workspaces, plans, subscriptions, invoices — are separate features with no
 * specification here, and are NOT stubbed (BO-D8). What the dashboard shows is what this build
 * actually knows about: who is signed in, who else can administer the platform, and what the
 * audit log has recorded lately.
 */
class DashboardController extends Controller
{
    /** GET /backoffice/dashboard */
    public function index(): View
    {
        $user = Auth::guard('backoffice')->user();

        return view('backoffice.dashboard', [
            'user' => $user,
            'adminCount' => BackofficeUser::query()->active()->count(),
            'superAdminCount' => BackofficeUser::query()->active()->superAdmins()->count(),
            /*
             * Recent FAILURES, not recent everything.
             *
             * A dashboard listing successful sign-ins is a list nobody reads. The rows worth
             * putting in front of an administrator on arrival are the ones that did not work —
             * refused codes, refused passwords, resets for addresses that do not exist.
             */
            'recentFailures' => BackofficeAuditLog::query()
                ->failures()
                ->latest('created_at')
                ->limit(10)
                ->get()
                ->map->toPayload()
                ->all(),
            'recentEvents' => BackofficeAuditLog::query()
                ->latest('created_at')
                ->limit(15)
                ->with('user')
                ->get()
                ->map->toPayload()
                ->all(),
        ]);
    }
}
