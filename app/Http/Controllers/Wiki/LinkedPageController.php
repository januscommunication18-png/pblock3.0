<?php

namespace App\Http\Controllers\Wiki;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectPage;
use App\Models\WikiCollection;
use App\Models\WikiPage;
use App\Services\WorkspaceApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Linked Pages (docs/features/wiki-linked-pages.md) — a Project Page shown inside a collection.
 *
 * The project page does not move, change, or gain a copy. What is created here is a `wiki_pages`
 * row that POINTS at it, so the same underlying row is what both places render and an edit in
 * either is an edit to one document.
 *
 * Everything is confined to the current workspace. Both models are `BelongsToTenant`, so a
 * cross-tenant id resolves to nothing anyway — but §8 asks for a rejection, and "the query
 * returned nothing" is not one, so the checks are written out.
 */
class LinkedPageController extends Controller
{
    public function __construct(private readonly WorkspaceApps $apps) {}

    /**
     * GET /wiki/collections/{collection}/linkable/projects?q=
     *
     * Searched on the SERVER, not filtered in the browser: §11, and a workspace with a thousand
     * pages cannot preload them into a combo box.
     */
    public function projects(Request $request, WikiCollection $collection): JsonResponse
    {
        $this->guard($collection);

        $q = trim((string) $request->query('q'));

        $projects = Project::query()
            // The filter that already answers "which projects may this person see".
            ->visibleTo(Auth::user())
            ->when($q !== '', fn ($query) => $query->where('name', 'like', '%'.$q.'%'))
            ->orderBy('name')
            ->limit(100)
            ->get()
            // Pages can be switched off per project; one with them off has nothing to offer.
            ->filter(fn (Project $p) => $p->featureEnabled('pages'))
            ->map(fn (Project $p) => ['value' => (string) $p->id, 'label' => $p->name])
            ->values()
            ->all();

        return response()->json(['ok' => true, 'options' => $projects]);
    }

    /** GET /wiki/collections/{collection}/linkable/pages?project=&q= */
    public function pages(Request $request, WikiCollection $collection): JsonResponse
    {
        $this->guard($collection);

        $project = Project::query()->visibleTo(Auth::user())->find((int) $request->query('project'));

        // Not a 403: whether that project exists is not this endpoint's to disclose.
        if (! $project || ! Auth::user()->can('viewAny', [ProjectPage::class, $project])) {
            return response()->json(['ok' => true, 'options' => []]);
        }

        $q = trim((string) $request->query('q'));

        // Already-linked pages are dropped rather than offered and then refused (§12).
        $taken = WikiPage::query()
            ->where('wiki_collection_id', $collection->id)
            ->where('source_type', WikiPage::SOURCE_PROJECT_PAGE)
            ->pluck('source_page_id')
            ->all();

        $options = ProjectPage::query()
            ->where('project_id', $project->id)
            ->whereNull('archived_at')
            ->whereNotIn('id', $taken)
            ->when($q !== '', fn ($query) => $query->where('title', 'like', '%'.$q.'%'))
            ->orderBy('title')
            ->limit(100)
            ->get()
            ->map(fn (ProjectPage $p) => ['value' => (string) $p->id, 'label' => $p->title])
            ->all();

        return response()->json(['ok' => true, 'options' => $options]);
    }

    /**
     * POST /wiki/collections/{collection}/linked-pages — link a project page in.
     *
     * One transaction (§18): a link that half-happens is a row pointing at nothing.
     */
    public function store(Request $request, WikiCollection $collection): JsonResponse
    {
        $this->guard($collection);

        $data = $request->validate([
            'project_id' => ['required', 'integer'],
            'page_id' => ['required', 'integer'],
        ]);

        $project = Project::query()->visibleTo(Auth::user())->find($data['project_id']);

        // WLP-13 — the workspace is the boundary, and it is checked here rather than left to a
        // global scope to silently return nothing.
        abort_unless($project, 422, 'That project is not in this workspace.');

        $page = ProjectPage::query()
            ->where('project_id', $project->id)
            ->whereNull('archived_at')
            ->find($data['page_id']);

        abort_unless($page, 422, 'That page is no longer available.');

        // WLP-14 — re-checked at submission, not only when the picker was drawn.
        abort_unless(Auth::user()->can('view', $page), 403);

        // §12 — checked before the insert so the message is the informative one rather than a
        // constraint violation. The unique index behind it is what makes the race safe.
        $already = WikiPage::query()
            ->where('wiki_collection_id', $collection->id)
            ->where('source_type', WikiPage::SOURCE_PROJECT_PAGE)
            ->where('source_page_id', $page->id)
            ->exists();

        abort_if($already, 422, 'This page is already linked to this collection.');

        $linked = DB::transaction(function () use ($collection, $page) {
            return WikiPage::create([
                'tenant_id' => $collection->tenant_id,
                'wiki_collection_id' => $collection->id,
                // Deliberately null: the title and the body are the source's, resolved through
                // the pointer. Storing them here would be the copy this feature exists to avoid.
                'title' => null,
                'content' => null,
                'source_type' => WikiPage::SOURCE_PROJECT_PAGE,
                'source_page_id' => $page->id,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                // Appended, never inserted — a linked page must not displace an order somebody
                // already arranged.
                'position' => (int) WikiPage::query()
                    ->where('wiki_collection_id', $collection->id)
                    ->max('position') + 1,
            ]);
        });

        Log::info('wiki.page.linked', [
            'collection' => $collection->id, 'project' => $project->id,
            'page' => $page->id, 'by' => Auth::id(),
        ]);

        return response()->json([
            'ok' => true,
            'page' => $linked->fresh()->toCard(),
            'message' => 'Page linked. It still lives in '.$project->name.'.',
        ]);
    }

    /** Whoever may add a page to this collection may link one into it. */
    private function guard(WikiCollection $collection): void
    {
        $user = Auth::user();

        abort_unless($this->apps->isEnabled($user->currentWorkspace, 'wiki'), 404);
        abort_unless($collection->writableBy($user), 403);
    }
}
