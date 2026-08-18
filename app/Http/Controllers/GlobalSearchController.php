<?php

namespace App\Http\Controllers;

use App\Services\Search\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GET /search — the command palette's data source (docs/features/global-search.md).
 *
 * Takes no scope from the request beyond the query text. Which workspace is searched comes from
 * the signed-in user's active workspace, and which projects within it from their memberships —
 * so there is no parameter here for a caller to tamper with in order to read somewhere else.
 */
class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        $user = Auth::user();
        $query = (string) ($data['q'] ?? '');

        $result = $search->run($user, $user->current_workspace_id, $query);

        return response()->json([
            'query' => $query,
            'groups' => $result['groups'],
            // Distinguishes "we looked and found nothing" from "we could not look" (§13.4 /
            // §13.5). The client renders two different states from this.
            'error' => $result['error'],
        ], $result['error'] ? 503 : 200);
    }
}
