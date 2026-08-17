<?php

namespace App\Http\Controllers\Wiki;

use App\Http\Controllers\Controller;
use App\Models\WikiCollection;
use App\Models\WikiCollectionGroup;
use App\Models\WikiPage;
use App\Services\WorkspaceApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Groups inside a collection (docs/features/wiki.md) — the Group view's sections.
 *
 * Same write gate as pages: whoever may add a page may arrange them. Grouping is an editorial
 * act, not an administrative one, so it does not need the tighter "manage the collection" gate
 * that publishing and access do.
 */
class GroupController extends Controller
{
    public function __construct(private readonly WorkspaceApps $apps) {}

    /** POST /wiki/collections/{collection}/groups */
    public function store(Request $request, WikiCollection $collection): JsonResponse
    {
        $this->guard($collection);

        $parentId = $this->parentFor($request, $collection);

        $group = WikiCollectionGroup::create($this->validated($request) + [
            'wiki_collection_id' => $collection->id,
            'parent_id' => $parentId,
            'created_by' => Auth::id(),
            // Position is per level: a sub-group is ordered against its siblings, not against
            // the top-level sections it is drawn inside.
            'position' => (int) WikiCollectionGroup::query()
                ->where('wiki_collection_id', $collection->id)
                ->where('parent_id', $parentId)
                ->max('position') + 1,
        ]);

        return response()->json([
            'ok' => true,
            'groups' => $this->groups($collection),
            'group' => $group->toCard(),
            'message' => 'Group created.',
        ]);
    }

    /** PATCH /wiki/collections/{collection}/groups/{group} */
    public function update(Request $request, WikiCollection $collection, WikiCollectionGroup $group): JsonResponse
    {
        $this->guard($collection);
        abort_unless((int) $group->wiki_collection_id === (int) $collection->id, 404);

        $group->fill($this->validated($request))->save();

        return response()->json([
            'ok' => true,
            'groups' => $this->groups($collection),
            'message' => 'Group updated.',
        ]);
    }

    /**
     * DELETE /wiki/collections/{collection}/groups/{group}
     *
     * The group goes; its pages do not. Removing a section is a decision about arrangement,
     * and the pages fall back to Ungrouped rather than being deleted along with it.
     */
    public function destroy(WikiCollection $collection, WikiCollectionGroup $group): JsonResponse
    {
        $this->guard($collection);
        abort_unless((int) $group->wiki_collection_id === (int) $collection->id, 404);

        // Sub-groups are promoted rather than swept away with the parent. Removing a heading
        // is a decision about arrangement; the sections beneath it were separate decisions.
        $promoted = WikiCollectionGroup::query()->where('parent_id', $group->id)->count();

        WikiCollectionGroup::query()
            ->where('parent_id', $group->id)
            ->update(['parent_id' => null]);

        $group->delete();

        return response()->json([
            'ok' => true,
            'groups' => $this->groups($collection),
            'message' => $promoted
                ? 'Group removed. Its pages are now ungrouped and its sub-groups moved up a level.'
                : 'Group removed. Its pages are now ungrouped.',
        ]);
    }

    /** PATCH /wiki/collections/{collection}/groups/reorder — the sections, after a drag. */
    public function reorder(Request $request, WikiCollection $collection): JsonResponse
    {
        $this->guard($collection);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            // Which level was dragged. Positions are per level, so reordering the sub-groups
            // of one section must not renumber the top-level ones out from under it.
            'parent_id' => ['nullable', 'integer'],
        ]);

        $parentId = $data['parent_id'] ?? null;

        $own = WikiCollectionGroup::query()
            ->where('wiki_collection_id', $collection->id)
            ->where('parent_id', $parentId)
            ->pluck('id')
            ->all();

        $position = 0;

        foreach ($data['ids'] as $id) {
            if (! in_array((int) $id, $own, true)) {
                continue;
            }

            WikiCollectionGroup::query()->whereKey((int) $id)->update(['position' => $position++]);
        }

        return response()->json(['ok' => true, 'groups' => $this->groups($collection)]);
    }

    /** PATCH /wiki/collections/{collection}/pages/{page}/group — move one page between groups. */
    public function assign(Request $request, WikiCollection $collection, WikiPage $page): JsonResponse
    {
        $this->guard($collection);
        abort_unless((int) $page->wiki_collection_id === (int) $collection->id, 404);

        $data = $request->validate(['group_id' => ['nullable', 'integer']]);
        $groupId = $data['group_id'] ?? null;

        if ($groupId) {
            // Only a group of THIS collection: a page cannot be filed under a section of
            // something it is not in.
            abort_unless(
                WikiCollectionGroup::query()
                    ->where('wiki_collection_id', $collection->id)
                    ->whereKey($groupId)
                    ->exists(),
                422,
                'That group does not belong to this collection.',
            );
        }

        $page->forceFill(['wiki_group_id' => $groupId, 'updated_by' => Auth::id()])->save();

        return response()->json(['ok' => true, 'message' => 'Page moved.']);
    }

    /**
     * The parent asked for, checked.
     *
     * Nesting stops at one level. Deeper is expressible in the data and unreadable on the
     * page: the Group view draws a card per section, and a card inside a card inside a card
     * is an outline nobody can scan. A collection needing three levels wants two collections.
     */
    private function parentFor(Request $request, WikiCollection $collection): ?int
    {
        $data = $request->validate(['parent_id' => ['nullable', 'integer']]);
        $parentId = $data['parent_id'] ?? null;

        if (! $parentId) {
            return null;
        }

        $parent = WikiCollectionGroup::query()
            ->where('wiki_collection_id', $collection->id)
            ->find($parentId);

        abort_unless((bool) $parent, 422, 'That group is not in this collection.');
        abort_if($parent->isSubGroup(), 422, 'Groups can only be nested one level deep.');

        return (int) $parent->id;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'label' => ['nullable', 'string', 'max:60'],
            'short_description' => ['nullable', 'string', 'max:200'],
            'long_description' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function groups(WikiCollection $collection): array
    {
        return WikiCollectionGroup::query()
            ->where('wiki_collection_id', $collection->id)
            ->orderBy('position')
            ->get()
            ->map(fn (WikiCollectionGroup $g) => $g->toCard())
            ->all();
    }

    /** Whoever may add a page may arrange them — see PageController for the same rule. */
    private function guard(WikiCollection $collection): void
    {
        $user = Auth::user();

        abort_unless($this->apps->isEnabled($user->currentWorkspace, 'wiki'), 404);
        // WikiCollection::writableBy() is the whole rule, including the one that closes an
        // archived collection to everybody but the people who can restore it.
        abort_unless($collection->writableBy($user), 403);
    }
}
