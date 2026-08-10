<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\StoreWorkspaceRequest;
use App\Models\Workspace;
use App\Services\WorkspaceCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Additional workspace creation from inside the app (spec §7). Includes "Choose your
 * view": Agile is selectable; Classic is Coming Soon and cannot be selected or created.
 */
class CreateWorkspaceController extends Controller
{
    public function __construct(private readonly WorkspaceCreator $creator) {}

    /** GET /workspaces/create */
    public function show(): View|RedirectResponse
    {
        $user = Auth::user();

        // Reached only from the authenticated app; if the user has no workspace yet,
        // send them through first-workspace onboarding instead.
        if (! $user->workspaces()->exists()) {
            return redirect()->route('onboarding.workspace');
        }

        return view('workspace.create', [
            'user' => $user,
            'teamSizes' => config('workspace.team_sizes'),
            'views' => config('workspace.views'),
            'defaultView' => config('workspace.default_view'),
            'classicEnabled' => (bool) config('workspace.classic_enabled'),
        ]);
    }

    /** POST /workspaces */
    public function store(StoreWorkspaceRequest $request): RedirectResponse
    {
        $workspace = $this->creator->create(Auth::user(), $request->workspaceData());

        return redirect()->route('welcome')
            ->with('status', "Workspace \"{$workspace->name}\" created.");
    }

    /** GET /workspaces/slug-available?slug=... — live availability check (WS-005). */
    public function slugAvailable(Request $request): JsonResponse
    {
        $slug = Str::slug((string) $request->query('slug'));

        $valid = $slug !== ''
            && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1
            && ! in_array($slug, config('workspace.reserved_slugs'), true);

        $available = $valid && ! Workspace::query()->where('slug', $slug)->exists();

        return response()->json(['slug' => $slug, 'available' => $available]);
    }
}
