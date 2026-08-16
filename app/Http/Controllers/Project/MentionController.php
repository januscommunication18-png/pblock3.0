<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\WorkItem;
use App\Services\MentionableUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Who the editor may offer when somebody types `@` (mentions §5, §16, §28).
 *
 * Gated on VIEWING the project's work items rather than editing them: a Commenter writing a
 * reply needs the same list as a Contributor writing a description, and neither is changing
 * anything by looking somebody up.
 *
 * The list itself comes from MentionableUsers, the same service that later decides whether a
 * submitted id counts (§23/§24) — so the autocomplete can never suggest somebody the backend
 * will then reject, and never hide somebody it would accept.
 */
class MentionController extends Controller
{
    public function __construct(private readonly MentionableUsers $mentionable) {}

    /** GET /projects/{project}/mentionable-users?search=roh */
    public function index(Request $request, Project $project): JsonResponse
    {
        abort_unless(Auth::user()->can('viewAny', [WorkItem::class, $project]), 404);

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'ok' => true,
            // §28: a bounded result set, whatever was typed. The editor debounces; this is
            // what stops a two-thousand-person workspace answering with two thousand rows.
            'users' => $this->mentionable->search(
                $project,
                $data['search'] ?? null,
                (int) config('projects.mention_results'),
            ),
        ]);
    }
}
