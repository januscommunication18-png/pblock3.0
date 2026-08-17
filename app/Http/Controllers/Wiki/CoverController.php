<?php

namespace App\Http\Controllers\Wiki;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wiki\UpdateCoverRequest;
use App\Models\WikiCollection;
use App\Models\WikiCover;
use App\Services\WorkspaceApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * A collection's Cover Page settings (docs/features/wiki-cover-page.md).
 *
 * There is no `show`: the cover is part of the collection screen's bootstrap payload, like the
 * groups and the pages beside it. A second round trip to fill in a card that is already on the
 * screen buys nothing.
 */
class CoverController extends Controller
{
    public function __construct(private readonly WorkspaceApps $apps) {}

    /** PATCH /wiki/collections/{collection}/cover */
    public function update(UpdateCoverRequest $request, WikiCollection $collection): JsonResponse
    {
        $this->guard($collection);

        $cover = WikiCover::forCollection($collection);

        // The row is written on the first save, not on the first read — see
        // WikiCover::forCollection().
        if (! $cover->exists) {
            $cover->created_by = Auth::id();
        }

        $cover->fill($request->validated() + ['updated_by' => Auth::id()])->save();

        return response()->json([
            'ok' => true,
            'cover' => $cover->toCard(),
            'message' => 'Cover saved.',
        ]);
    }

    /**
     * Whoever may arrange this collection may arrange its front door.
     *
     * The same gate GroupController enforces, and for the same reason: a cover is editorial.
     * It reaches the public only through publishing, which is behind the tighter `canManage`
     * gate in CollectionController and stays there.
     */
    private function guard(WikiCollection $collection): void
    {
        $user = Auth::user();

        abort_unless($this->apps->isEnabled($user->currentWorkspace, 'wiki'), 404);
        // WikiCollection::writableBy() is the whole rule, including the one that closes an
        // archived collection to everybody but the people who can restore it.
        abort_unless($collection->writableBy($user), 403);
    }
}
