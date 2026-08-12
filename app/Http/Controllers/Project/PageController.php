<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StorePageRequest;
use App\Http\Requests\Project\UpdatePageRequest;
use App\Models\Project;
use App\Models\ProjectPage;
use App\Models\ProjectPageVersion;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\PageVersioner;
use App\Services\ProjectNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Project Workspace → Pages (Pages §4, §7-§9, §12).
 *
 * Built like Modules, Cycles and Epics: one Vue root and one payload for both the list and a
 * single page, with `pagePageId` telling the client to render the editor instead of the
 * listing — so a page URL is real and linkable rather than a list with a document over it.
 *
 * Every action starts from ProjectPagePolicy, which refuses when Pages is switched off for the
 * project. Unlike Epics and Modules, that includes READING: §5 restricts direct access to a
 * disabled project's pages, where the Epic rules deliberately keep records readable. Cross-
 * project ids 404 rather than 403, so a response never confirms that a page exists somewhere
 * the user cannot see.
 */
class PageController extends Controller
{
    public function __construct(
        private readonly ProjectNavigation $navigation,
        private readonly PageVersioner $versions,
    ) {}

    /** GET /projects/{project}/pages */
    public function index(Project $project): View
    {
        abort_unless(Auth::user()->can('viewAny', [ProjectPage::class, $project]), 404);

        return $this->screen($project);
    }

    /** GET /projects/{project}/pages/{page} — the stable per-page URL (§9). */
    public function show(Project $project, ProjectPage $page): View
    {
        $this->guardPage($project, $page, 'view');

        return $this->screen($project, $page);
    }

    /** POST /projects/{project}/pages (§8). */
    public function store(StorePageRequest $request, Project $project): JsonResponse
    {
        // The ability is checked by StorePageRequest::authorize(), which runs first.
        $data = $request->validated();

        $page = ProjectPage::create($data + [
            // §8/AC-06: the project comes from the URL, never from the payload.
            'project_id' => $project->id,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        // The first version is the page as created, so the history has a floor to restore to.
        $this->versions->record($page, Auth::user());

        return response()->json([
            'ok' => true,
            'page' => $this->card($page->fresh(['creator', 'editor', 'parent'])),
            'message' => 'Page created.',
        ], 201);
    }

    /** PATCH /projects/{project}/pages/{page} (§12). */
    public function update(UpdatePageRequest $request, Project $project, ProjectPage $page): JsonResponse
    {
        // UpdatePageRequest::authorize() has checked the ability; this is the ownership half —
        // a page reached through the wrong project's URL is not found.
        abort_unless($page->project_id === $project->id, 404);

        // §8: updated-by and updated-at are recorded automatically, never sent by the client.
        $page->fill($request->validated() + ['updated_by' => Auth::id()])->save();

        // AFTER the save, so a version always describes a state the document reached. Saves
        // that changed nothing, and bursts from the autosave, are coalesced away in there.
        $this->versions->record($page, Auth::user());

        return response()->json([
            'ok' => true,
            'page' => $this->card($page->fresh(['creator', 'editor', 'parent'])),
            'message' => 'Saved.',
        ]);
    }

    /** POST /projects/{project}/pages/{page}/archive — §9's status, both directions. */
    public function archive(Project $project, ProjectPage $page): JsonResponse
    {
        $this->guardPage($project, $page, 'archive');

        $page->forceFill([
            'archived_at' => $page->isArchived() ? null : now(),
            'updated_by' => Auth::id(),
        ])->save();

        return response()->json([
            'ok' => true,
            'page' => $this->card($page->fresh(['creator', 'editor', 'parent'])),
            'message' => $page->isArchived() ? 'Page archived.' : 'Page restored.',
        ]);
    }

    /**
     * DELETE /projects/{project}/pages/{page}.
     *
     * A soft delete: §5's principle is that the feature hides data rather than destroying it,
     * and the same instinct applies to removing one page — its content and history survive,
     * and its children are re-parented to nothing rather than deleted with it (the FK is
     * nullOnDelete).
     */
    public function destroy(Project $project, ProjectPage $page): JsonResponse
    {
        $this->guardPage($project, $page, 'delete');
        $page->delete();

        return response()->json(['ok' => true, 'message' => 'Page deleted.']);
    }

    /** GET /projects/{project}/pages/{page}/versions — the history list. */
    public function versions(Project $project, ProjectPage $page): JsonResponse
    {
        $this->guardPage($project, $page, 'view');

        return response()->json(['ok' => true, 'versions' => $this->versionList($page)]);
    }

    /**
     * POST /projects/{project}/pages/{page}/versions/{version}/restore.
     *
     * Restoring is an edit, not a rewind: the state being replaced is already a version, and
     * the restore adds one of its own — so the history shows that it happened and going back
     * again is possible.
     */
    public function restoreVersion(Project $project, ProjectPage $page, ProjectPageVersion $version): JsonResponse
    {
        $this->guardPage($project, $page, 'update');
        abort_unless((int) $version->project_page_id === (int) $page->id, 404);

        $restored = $this->versions->restore($page, $version, Auth::user());

        return response()->json([
            'ok' => true,
            'page' => $this->card($restored->fresh(['creator', 'editor', 'parent'])),
            'content' => $restored->content,
            'versions' => $this->versionList($restored),
            'message' => 'Version restored.',
        ]);
    }

    /**
     * The history, newest first.
     *
     * Without the bodies: a list of fifty versions is a list, not fifty documents. The content
     * arrives with the restore.
     *
     * @return array<int, array<string, mixed>>
     */
    private function versionList(ProjectPage $page): array
    {
        return ProjectPageVersion::query()
            ->where('project_page_id', $page->id)
            ->with('editor')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ProjectPageVersion $v) => [
                'id' => $v->id,
                'title' => $v->title,
                'status' => $v->status,
                'edited_by' => $v->editor ? $this->person($v->editor) : null,
                'at' => $v->updated_at?->toIso8601String(),
            ])->all();
    }

    /** The Pages screen, as a list or focused on one page. */
    private function screen(Project $project, ?ProjectPage $pageDoc = null): View
    {
        $pages = ProjectPage::query()
            ->forProject($project->id)
            ->with(['creator', 'editor', 'parent'])
            ->orderByDesc('updated_at')
            ->get();

        return view('projects.pages', [
            'workspace' => Auth::user()->currentWorkspace,
            'user' => Auth::user(),
            'project' => $project,
            'tabs' => $this->navigation->tabs($project),
            'activeTab' => 'pages',
            'projects' => $this->navigation->sidebarProjects(Auth::user()),
            'canCreateProject' => Auth::user()->can('create', [WorkItem::class, $project]),
            'canManage' => Auth::user()->can('manage', $project),
            'bootstrap' => [
                'project' => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'identifier' => $project->identifier,
                    'emoji' => $project->emoji,
                ],
                'pages' => $pages->map(fn (ProjectPage $p) => $this->card($p))->all(),
                // Set only on the per-page URL: render the editor, not the listing.
                'pageId' => $pageDoc?->id,
                // The body travels only for the page being opened — a listing of fifty
                // documents has no use for fifty documents' worth of HTML.
                'content' => $pageDoc?->content,
                'versions' => $pageDoc ? $this->versionList($pageDoc) : [],
                'canCreate' => Auth::user()->can('create', [ProjectPage::class, $project]),
                'canDelete' => Auth::user()->can('manage', $project),
                'titleMax' => (int) config('projects.page_title_max'),
                'statuses' => collect(config('projects.page_statuses'))
                    ->map(fn (array $meta, string $key) => ['key' => $key] + $meta)->values()->all(),
                // The editor's Pro licence. Empty in a checkout without one, which is what
                // makes the Pro plugins inert rather than absent.
                'editorLicense' => (string) config('projects.jodit_license'),
                // AC-09/AC-10: mentions are Phase 2. The editor says so rather than pretending.
                'mentionsComingSoon' => true,
                'endpoints' => [
                    'list' => route('projects.pages', $project),
                    'store' => route('projects.pages.store', $project),
                    'page' => route('projects.pages.show', ['project' => $project->id, 'page' => '__ID__']),
                    'update' => route('projects.pages.update', ['project' => $project->id, 'page' => '__ID__']),
                    'archive' => route('projects.pages.archive', ['project' => $project->id, 'page' => '__ID__']),
                    'versions' => route('projects.pages.versions', ['project' => $project->id, 'page' => '__ID__']),
                    'restoreVersion' => route('projects.pages.versions.restore', [
                        'project' => $project->id, 'page' => '__ID__', 'version' => '__VERSION__',
                    ]),
                    'destroy' => route('projects.pages.destroy', ['project' => $project->id, 'page' => '__ID__']),
                    'mediaUpload' => route('projects.work-items.media.store', $project),
                ],
            ],
        ]);
    }

    /**
     * The page must belong to THIS project — never trust the URL alone — and the user must
     * hold the ability.
     */
    private function guardPage(Project $project, ProjectPage $page, string $ability): void
    {
        abort_unless($page->project_id === $project->id, 404);
        abort_unless(Auth::user()->can($ability, $page), $ability === 'view' ? 404 : 403);
    }

    /** @return array<string, mixed> */
    private function card(ProjectPage $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'status' => $page->status,
            'status_label' => $page->statusMeta()['label'],
            'status_color' => $page->statusMeta()['color'],
            'published' => $page->isPublished(),
            'archived' => $page->isArchived(),
            'parent' => $page->parent ? ['id' => $page->parent->id, 'title' => $page->parent->title] : null,
            'created_by' => $page->creator ? $this->person($page->creator) : null,
            'updated_by' => $page->editor ? $this->person($page->editor) : null,
            'created_at' => $page->created_at?->toIso8601String(),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function person(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->displayName(),
            'initial' => $user->initial(), 'avatar_url' => $user->avatar_url,
        ];
    }
}
