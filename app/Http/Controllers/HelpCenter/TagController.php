<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Http\Requests\HelpCenter\StoreTagRequest;
use App\Models\HelpCenterSpace;
use App\Models\HelpCenterTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * A Space's tags (docs/features/help-center.md, P14).
 *
 * Two endpoints, nested under the Space, for the same reason a Request's actions are: the tag
 * and its Space are checked together, so a tag belonging to another Space cannot be reached
 * through this Space's URL.
 *
 * There is no update. Renaming a tag is a different question — every Request already carrying it
 * either follows the new name or does not — and it is not one this change was asked to answer.
 */
class TagController extends Controller
{
    use GuardsHelpCenter;

    /** POST /help-center/spaces/{space}/tags */
    public function store(StoreTagRequest $request, HelpCenterSpace $space): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);

        $name = (string) $request->validated('name');

        $tag = $space->tags()->create([
            'tenant_id' => $space->tenant_id,
            'name' => $name,
            // Written from the same function the request compared with, so a name that passed
            // validation cannot then collide with the unique index.
            'name_key' => HelpCenterTag::key($name),
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'ok' => true,
            'tag' => $tag->toPayload(),
            'message' => 'Tag added.',
        ]);
    }

    /**
     * DELETE /help-center/spaces/{space}/tags/{tag}
     *
     * The tag is checked to belong to the Space in the URL, not merely to exist: without that,
     * an id from another Space in the same workspace would be deleted by whoever may manage
     * THIS one, which is a Space Lead reaching into a Space they do not lead.
     */
    public function destroy(HelpCenterSpace $space, HelpCenterTag $tag): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);
        abort_unless((int) $tag->help_center_space_id === (int) $space->id, 404);

        $tag->delete();

        return response()->json(['ok' => true, 'message' => 'Tag removed.']);
    }
}
