<?php

namespace App\Http\Controllers\Wiki;

use App\Http\Controllers\Controller;
use App\Models\WikiCollection;
use App\Models\WikiCollectionGroup;
use App\Models\WikiLabel;
use App\Models\WikiPage;
use App\Services\RichTextSanitizer;
use App\Services\WorkspaceApps;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Wiki pages (docs/features/wiki.md).
 *
 * Deliberately the same shape as Project Pages: create from a title, land straight in the
 * editor, autosave the body. Teams "shouldn't need to learn another editor", and they should
 * not have to learn another workflow around it either.
 */
class PageController extends Controller
{
    public function __construct(
        private readonly WorkspaceApps $apps,
        private readonly RichTextSanitizer $sanitizer,
    ) {}

    /** POST /wiki/collections/{collection}/pages — name it, then open it. */
    public function store(Request $request, WikiCollection $collection): JsonResponse
    {
        $this->guard($collection);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            // Set when the page is being made underneath another one.
            'parent_id' => ['nullable', 'integer'],
            // Set when it is being made inside a group, from that group's own "Add page".
            'group_id' => ['nullable', 'integer'],
        ]);

        $parentId = $data['parent_id'] ?? null;
        $groupId = $data['group_id'] ?? null;

        if ($groupId) {
            abort_unless(
                WikiCollectionGroup::query()
                    ->where('wiki_collection_id', $collection->id)
                    ->whereKey($groupId)
                    ->exists(),
                422,
                'That group is not in this collection.',
            );
        }

        if ($parentId) {
            // Only within this collection — a page cannot be nested under one it is not with.
            abort_unless(
                WikiPage::query()
                    ->where('wiki_collection_id', $collection->id)
                    ->whereKey($parentId)
                    ->exists(),
                422,
                'That page cannot be the parent.',
            );
        }

        $page = WikiPage::create([
            // The collection comes from the URL, never from the payload.
            'wiki_collection_id' => $collection->id,
            'title' => trim($data['title']),
            'parent_id' => $parentId,
            // A sub-page inherits its parent's group: it is part of the same section by
            // definition, and leaving it ungrouped would file it away from its own parent.
            // The parent wins over an explicit group for the same reason.
            'wiki_group_id' => $parentId
                ? WikiPage::query()->whereKey($parentId)->value('wiki_group_id')
                : $groupId,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
            'position' => (int) WikiPage::query()->where('wiki_collection_id', $collection->id)->max('position') + 1,
        ]);

        return response()->json([
            'ok' => true,
            'page' => $page->toCard(),
            'url' => route('wiki.pages.show', ['collection' => $collection->id, 'page' => $page->id]),
        ]);
    }

    /** GET /wiki/collections/{collection}/pages/{page} — the editor. */
    public function show(WikiCollection $collection, WikiPage $page): View
    {
        $this->guardRead($collection);
        abort_unless((int) $page->wiki_collection_id === (int) $collection->id, 404);

        return view('wiki.page', [
            'workspace' => Auth::user()->currentWorkspace,
            'collection' => $collection,
            'page' => $page,
            'bootstrap' => [
                'page' => $page->toCard() + ['content' => $page->content],
                'collection' => $collection->toCard(),
                'canEdit' => $collection->writableBy(Auth::user()),
                'titleMax' => 200,
                // The Jodit Pro licence, same source Project Pages reads it from. Without it
                // the Pro plugins load but stay inert, which reads as "the toolbar is missing
                // half its buttons" rather than as an error.
                'editorLicense' => (string) config('projects.jodit_license'),
                'endpoints' => [
                    'update' => route('wiki.pages.update', ['collection' => $collection->id, 'page' => $page->id]),
                    'collection' => route('wiki.collections.show', $collection),
                    'mediaUpload' => '',
                ],
            ],
        ]);
    }

    /** PATCH /wiki/collections/{collection}/pages/{page} — the autosave. */
    public function update(Request $request, WikiCollection $collection, WikiPage $page): JsonResponse
    {
        $this->guard($collection);
        abort_unless((int) $page->wiki_collection_id === (int) $collection->id, 404);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'content' => ['sometimes', 'nullable', 'string'],
        ]);

        if (array_key_exists('content', $data)) {
            // Never stored raw: the editor is a rich-text field and its output is user input.
            $data['content'] = $this->sanitizer->sanitize((string) $data['content']);
        }

        // Updated-by and updated-at are recorded here, never sent by the client.
        $page->fill($data + ['updated_by' => Auth::id()])->save();

        return response()->json(['ok' => true, 'page' => $page->fresh()->toCard(), 'message' => 'Saved.']);
    }

    /**
     * PATCH /wiki/collections/{collection}/pages/{page}/details — the row's Edit action.
     *
     * Name, what it nests under, and its labels. Separate from the autosave: that one writes a
     * body every second or so, and none of this belongs in that path.
     */
    public function details(Request $request, WikiCollection $collection, WikiPage $page): JsonResponse
    {
        $this->guard($collection);
        abort_unless((int) $page->wiki_collection_id === (int) $collection->id, 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'parent_id' => ['nullable', 'integer'],
            'labels' => ['sometimes', 'array'],
            'labels.*' => ['integer'],
        ]);

        $parentId = $data['parent_id'] ?? null;

        if ($parentId) {
            // Only within this collection, and never itself — a page nested under itself
            // disappears from every tree that tries to draw it.
            $valid = WikiPage::query()
                ->where('wiki_collection_id', $collection->id)
                ->where('id', $parentId)
                ->where('id', '!=', $page->id)
                ->exists();

            abort_unless($valid, 422, 'That page cannot be the parent.');
        }

        $page->fill([
            'title' => trim($data['title']),
            'parent_id' => $parentId,
            'updated_by' => Auth::id(),
        ])->save();

        if (array_key_exists('labels', $data)) {
            $page->labels()->sync($this->ownLabelIds($data['labels']));
        }

        return response()->json([
            'ok' => true,
            'pages' => $this->pages($collection),
            'message' => 'Page updated.',
        ]);
    }

    /**
     * PATCH /wiki/collections/{collection}/pages/reorder — the tree after a drag.
     *
     * The whole tree arrives, flattened to (id, parent_id, position), rather than "this moved
     * there": one drop can renumber every sibling on both sides of the move, and reconstructing
     * that from a single delta is guesswork the client has already done properly.
     */
    public function reorder(Request $request, WikiCollection $collection): JsonResponse
    {
        $this->guard($collection);

        $data = $request->validate([
            'nodes' => ['required', 'array'],
            'nodes.*.id' => ['required', 'integer'],
            'nodes.*.parent_id' => ['nullable', 'integer'],
            'nodes.*.position' => ['required', 'integer', 'min:0'],
            // Sent by the Group view, which drags pages between sections as well as within
            // them. Absent from the List view's payload, which knows nothing about groups.
            'nodes.*.group_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        // Only this collection's pages, whatever the payload claims.
        $own = WikiPage::query()
            ->where('wiki_collection_id', $collection->id)
            ->pluck('id')
            ->all();

        $nodes = collect($data['nodes'])
            ->filter(fn (array $n) => in_array((int) $n['id'], $own, true))
            ->filter(fn (array $n) => $n['parent_id'] === null || in_array((int) $n['parent_id'], $own, true))
            // A page cannot parent itself; the cycle check below catches the longer loops.
            ->reject(fn (array $n) => (int) $n['id'] === (int) ($n['parent_id'] ?? 0))
            ->values();

        $parents = $nodes->pluck('parent_id', 'id')->map(fn ($p) => $p === null ? null : (int) $p)->all();

        abort_if($this->hasCycle($parents), 422, 'That move would nest a page inside itself.');

        $ownGroups = WikiCollectionGroup::query()
            ->where('wiki_collection_id', $collection->id)
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($nodes, $ownGroups) {
            foreach ($nodes as $node) {
                $changes = [
                    'parent_id' => $node['parent_id'] === null ? null : (int) $node['parent_id'],
                    'position' => (int) $node['position'],
                ];

                // Only when the caller said something about groups — the List view does not,
                // and a missing key must not read as "ungroup everything it touched".
                if (array_key_exists('group_id', $node)) {
                    $groupId = $node['group_id'] === null ? null : (int) $node['group_id'];
                    $changes['wiki_group_id'] = in_array($groupId, $ownGroups, true) ? $groupId : null;
                }

                WikiPage::query()->whereKey((int) $node['id'])->update($changes);
            }
        });

        return response()->json([
            'ok' => true,
            'pages' => $this->pages($collection),
        ]);
    }

    /**
     * Would this parent map close a loop?
     *
     * A → B → A is not something the UI can produce, but the endpoint is reachable without it,
     * and a cycle makes the tree impossible to render — every walk of it runs forever.
     *
     * @param  array<int, int|null>  $parents
     */
    private function hasCycle(array $parents): bool
    {
        foreach (array_keys($parents) as $start) {
            $seen = [];
            $at = $start;

            while ($at !== null && isset($parents[$at])) {
                if (isset($seen[$at])) {
                    return true;
                }

                $seen[$at] = true;
                $at = $parents[$at];
            }
        }

        return false;
    }

    /**
     * DELETE /wiki/collections/{collection}/pages/{page} — "Remove from collection".
     *
     * ARCHIVES rather than destroys: §"Archive instead of delete" is the whole posture of this
     * feature, and a page removed by mistake is a page somebody wrote.
     */
    public function destroy(WikiCollection $collection, WikiPage $page): JsonResponse
    {
        $this->guard($collection);
        abort_unless((int) $page->wiki_collection_id === (int) $collection->id, 404);

        $page->forceFill(['archived_at' => now(), 'updated_by' => Auth::id()])->save();

        return response()->json([
            'ok' => true,
            'pages' => $this->pages($collection),
            'message' => 'Removed from collection. It is in Archived.',
        ]);
    }

    /**
     * Labels that belong to THIS workspace.
     *
     * The ids arrive from a form, so they are filtered rather than trusted — the tenant scope
     * on WikiLabel is what turns a stray id from another workspace into nothing at all.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function ownLabelIds(array $ids): array
    {
        return WikiLabel::query()->whereIn('id', $ids)->pluck('id')->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function pages(WikiCollection $collection): array
    {
        return WikiPage::query()
            ->where('wiki_collection_id', $collection->id)
            ->active()
            ->withCount('children')
            ->with(['creator', 'editor', 'labels', 'parent'])
            ->orderBy('position')
            ->get()
            ->map(fn (WikiPage $p) => $p->toCard() + [
                'url' => route('wiki.pages.show', ['collection' => $collection->id, 'page' => $p->id]),
            ])
            ->all();
    }

    /** Read access follows the collection — "access follows the collection". */
    private function guardRead(WikiCollection $collection): void
    {
        abort_unless($this->apps->isEnabled(Auth::user()->currentWorkspace, 'wiki'), 404);
        abort_unless($collection->openableBy(Auth::user()), 403);
    }

    private function guard(WikiCollection $collection): void
    {
        // Not guardRead() first: writableBy() already answers the read question, and chaining
        // them would run the membership EXISTS twice on every autosave.
        abort_unless($this->apps->isEnabled(Auth::user()->currentWorkspace, 'wiki'), 404);
        abort_unless($collection->writableBy(Auth::user()), 403);
    }
}
