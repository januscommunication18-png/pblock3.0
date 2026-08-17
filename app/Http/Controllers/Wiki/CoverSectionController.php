<?php

namespace App\Http\Controllers\Wiki;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wiki\StoreCoverSectionRequest;
use App\Models\WikiCollection;
use App\Models\WikiCollectionGroup;
use App\Models\WikiCover;
use App\Models\WikiCoverSection;
use App\Models\WikiPage;
use App\Services\WikiCoverCards;
use App\Services\WikiReader;
use App\Services\WorkspaceApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The Cover Page's section cards (docs/features/wiki-cover-page.md).
 *
 * Same gate as the cover itself and as the groups beside it: whoever may arrange this collection
 * may arrange its front door.
 */
class CoverSectionController extends Controller
{
    public function __construct(
        private readonly WorkspaceApps $apps,
        private readonly WikiReader $reader,
        private readonly WikiCoverCards $cards,
    ) {}

    /** POST /wiki/collections/{collection}/cover/sections */
    public function store(StoreCoverSectionRequest $request, WikiCollection $collection): JsonResponse
    {
        $this->guard($collection);

        $cover = $this->cover($collection);
        $data = $this->validatedFor($request, $collection);

        WikiCoverSection::create($data + [
            'tenant_id' => $collection->tenant_id,
            'wiki_cover_id' => $cover->id,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
            // Appended, never placed first — a new card must not displace an order somebody
            // chose. The same rule collections and pages already follow.
            'position' => (int) WikiCoverSection::query()
                ->where('wiki_cover_id', $cover->id)
                ->max('position') + 1,
        ]);

        return $this->list($collection, $cover, 'Section added.');
    }

    /** PATCH /wiki/collections/{collection}/cover/sections/{section} */
    public function update(
        StoreCoverSectionRequest $request,
        WikiCollection $collection,
        WikiCoverSection $section,
    ): JsonResponse {
        $this->guard($collection);

        $cover = $this->cover($collection);
        $this->own($cover, $section);

        $section->fill($this->validatedFor($request, $collection) + ['updated_by' => Auth::id()])->save();

        return $this->list($collection, $cover, 'Section updated.');
    }

    /**
     * DELETE /wiki/collections/{collection}/cover/sections/{section}
     *
     * The card goes; what it pointed at does not. Removing something from a landing page is a
     * decision about presentation (FR-WC-014).
     */
    public function destroy(WikiCollection $collection, WikiCoverSection $section): JsonResponse
    {
        $this->guard($collection);

        $cover = $this->cover($collection);
        $this->own($cover, $section);

        $section->delete();

        return $this->list($collection, $cover, 'Section removed.');
    }

    /** PATCH /wiki/collections/{collection}/cover/sections/reorder — after a drag, or Move up. */
    public function reorder(Request $request, WikiCollection $collection): JsonResponse
    {
        $this->guard($collection);

        $cover = $this->cover($collection);

        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        $own = WikiCoverSection::query()
            ->where('wiki_cover_id', $cover->id)
            ->pluck('id')
            ->all();

        $position = 0;

        foreach ($data['ids'] as $id) {
            // An id from somewhere else is ignored, not refused: the payload is the whole order,
            // and one stray entry should not throw away a legitimate reorder of everything else.
            if (! in_array((int) $id, $own, true)) {
                continue;
            }

            WikiCoverSection::query()->whereKey((int) $id)->update(['position' => $position++]);
        }

        return $this->list($collection, $cover);
    }

    /**
     * The card's fields, with the destination checked against THIS collection.
     *
     * A card cannot point at another collection's section or page — the cover it sits on has no
     * way to render that, and whichever collection you opened would show half a story.
     *
     * @return array<string, mixed>
     */
    private function validatedFor(StoreCoverSectionRequest $request, WikiCollection $collection): array
    {
        $data = $request->validated();

        $exists = $data['destination_type'] === WikiCoverSection::TO_GROUP
            ? WikiCollectionGroup::query()
                ->where('wiki_collection_id', $collection->id)
                ->whereKey($data['destination_id'])
                ->exists()
            : WikiPage::query()
                ->where('wiki_collection_id', $collection->id)
                ->active()
                ->whereKey($data['destination_id'])
                ->exists();

        abort_unless($exists, 422, 'That destination is not in this collection.');

        // None means none: an icon left over from a card that used to wear one would come back
        // the moment somebody switched the visual again.
        if ($data['visual_type'] !== WikiCoverSection::VISUAL_ICON) {
            $data['icon_key'] = null;
        }

        return $data;
    }

    /**
     * The cover this card belongs to, created if the collection has none.
     *
     * Adding a card IS configuring a cover, so unlike opening the screen it is a decision to
     * give the collection one.
     */
    private function cover(WikiCollection $collection): WikiCover
    {
        $cover = WikiCover::forCollection($collection);

        if (! $cover->exists) {
            $cover->created_by = Auth::id();
            $cover->updated_by = Auth::id();
            $cover->save();
        }

        return $cover;
    }

    private function own(WikiCover $cover, WikiCoverSection $section): void
    {
        abort_unless((int) $section->wiki_cover_id === (int) $cover->id, 404);
    }

    private function list(WikiCollection $collection, WikiCover $cover, ?string $message = null): JsonResponse
    {
        return response()->json(array_filter([
            'ok' => true,
            'sections' => $this->cards->forSettings($cover, $this->reader->sections($collection)),
            'message' => $message,
        ], fn ($value) => $value !== null));
    }

    /** Whoever may arrange this collection may arrange its front door — see CoverController. */
    private function guard(WikiCollection $collection): void
    {
        $user = Auth::user();

        abort_unless($this->apps->isEnabled($user->currentWorkspace, 'wiki'), 404);
        // WikiCollection::writableBy() is the whole rule, including the one that closes an
        // archived collection to everybody but the people who can restore it.
        abort_unless($collection->writableBy($user), 403);
    }
}
