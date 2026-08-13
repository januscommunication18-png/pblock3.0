<?php

namespace App\Http\Controllers;

use App\Http\Requests\Draft\PublishDraftRequest;
use App\Http\Requests\Draft\StoreDraftRequest;
use App\Http\Requests\Draft\UpdateDraftRequest;
use App\Models\Project;
use App\Models\ProjectItemState;
use App\Models\WorkItem;
use App\Services\ProjectItemStateProvisioner;
use App\Services\ProjectNavigation;
use App\Services\WorkItemCreator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Drafts (docs/features/drafts.md) — work items captured before they have a project.
 *
 * Workspace-level rather than project-level, which is the whole feature: a draft has no
 * project until it is published, so it cannot live under /projects/{project}/… like every
 * other work item screen. It sits beside Home in the sidebar instead.
 *
 * Two rules do the security work, and both are enforced here rather than by hiding things in
 * the UI. Drafts are private to their author (§4) — reached through `WorkItem::drafts()`, the
 * only query in the application that sees past the ExcludesDrafts global scope, and it takes
 * an author id, so there is no way to spell "everyone's drafts". And Viewers and Guests have
 * no drafts at all (D-D6), so every action starts from `createDraft`.
 *
 * Refusals are 404, never 403: a response must not confirm that somebody else's draft exists.
 */
class DraftController extends Controller
{
    public function __construct(
        private readonly ProjectNavigation $navigation,
        private readonly ProjectItemStateProvisioner $states,
        private readonly WorkItemCreator $creator,
    ) {}

    /** GET /drafts */
    public function index(): View
    {
        $user = Auth::user();
        abort_unless($user->can('createDraft', WorkItem::class), 404);

        return view('drafts.index', [
            'user' => $user,
            'workspace' => $user->currentWorkspace,
            'projects' => $this->navigation->sidebarProjects($user),
            'canCreateProject' => $user->can('create', [Project::class, $user->currentWorkspace]),
            'canDraft' => true,
            'bootstrap' => [
                'urls' => [
                    'store' => route('drafts.store'),
                    // Templates, resolved client-side by PB.withId — so the payload carries two
                    // URLs rather than two per row.
                    'draft' => route('drafts.update', ['draft' => '__ID__']),
                    'publish' => route('drafts.publish', ['draft' => '__ID__']),
                    // Description editor images. Workspace-level, not project-level, because a
                    // draft has no project — see DraftMediaController.
                    'mediaUpload' => route('drafts.media.store'),
                    'mediaGallery' => route('drafts.media.index'),
                ],
                'mediaMaxBytes' => (int) config('projects.media.max_kb') * 1024,
                'priorities' => config('projects.work_item_priorities'),
                // The Jodit Pro licence <pg-editor> runs under, same source as Pages. Without
                // it the Pro plugins load but stay inert, which reads as missing features
                // rather than as an error — so it is the first thing to check when they are.
                'editorLicense' => (string) config('projects.jodit_license'),
                'drafts' => $this->drafts(),
                // The publish modal's picker, with each project's states inlined. Resolved
                // server-side because "projects you may add work to" is a policy question, and
                // a client-side list would be a suggestion rather than a rule.
                'projects' => $this->publishTargets(),
            ],
        ]);
    }

    /** POST /drafts */
    public function store(StoreDraftRequest $request): JsonResponse
    {
        // StoreDraftRequest::authorize() has checked the ability. Only its validated fields
        // are used: project_id, state_id and the rest cannot be set on a draft, so a payload
        // carrying them writes nothing (DR-14).
        $draft = WorkItem::create($request->validated() + [
            'is_draft' => true,
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'ok' => true,
            'draft' => $this->card($draft),
            'message' => 'Draft saved.',
        ], 201);
    }

    /** PATCH /drafts/{draft} */
    public function update(UpdateDraftRequest $request, WorkItem $draft): JsonResponse
    {
        $draft->fill($request->validated())->save();

        // No re-read: the saved model already holds the new values and the new timestamp.
        return response()->json([
            'ok' => true,
            'draft' => $this->card($draft),
            'message' => 'Saved.',
        ]);
    }

    /**
     * POST /drafts/{draft}/publish — the draft becomes a work item (§5).
     *
     * The response carries the new item's URL rather than its payload: publishing is a
     * departure, and the client's next move is to open it in the project it now belongs to.
     */
    public function publish(PublishDraftRequest $request, WorkItem $draft): JsonResponse
    {
        // PublishDraftRequest::authorize() has already asked the policy about this draft and
        // this project together — it is their draft, and a project they may add work to.
        $item = $this->creator->publish(
            Auth::user(),
            $draft,
            $request->project(),
            ['state_id' => $request->validated('state_id')],
        );

        return response()->json([
            'ok' => true,
            'work_item' => [
                'id' => $item->id,
                'identifier' => $item->identifier,
                'url' => route('projects.work-items.show', ['project' => $item->project_id, 'workItem' => $item->id]),
            ],
            'message' => 'Published to '.$request->project()->name.'.',
        ]);
    }

    /**
     * DELETE /drafts/{draft} — discarded outright (§8).
     *
     * A hard delete, unlike a work item's archive: a draft is a scratch note, nothing has ever
     * referenced it, and there is no history to preserve because drafts are not audited.
     */
    public function destroy(WorkItem $draft): JsonResponse
    {
        abort_unless(Auth::user()->can('deleteDraft', $draft), 404);

        $draft->delete();

        return response()->json(['ok' => true, 'message' => 'Draft discarded.']);
    }

    /**
     * The signed-in user's drafts, newest edit first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function drafts(): array
    {
        return WorkItem::drafts(Auth::id())
            ->orderByDesc('updated_at')
            ->limit(config('projects.work_item_page_size'))
            ->get()
            ->map(fn (WorkItem $draft) => $this->card($draft))
            ->all();
    }

    /** @return array<string, mixed> */
    private function card(WorkItem $draft): array
    {
        return [
            'id' => $draft->id,
            'title' => $draft->title,
            'description' => $draft->description,
            'priority' => $draft->priority,
            'start_date' => $draft->start_date?->toDateString(),
            'due_date' => $draft->due_date?->toDateString(),
            'updated_at' => $draft->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Projects this user may publish a draft into, each with its own states.
     *
     * The same question `WorkItemPolicy@create` answers on a project's own create modal, asked
     * once per candidate — a draft must not be publishable somewhere its author could not have
     * created the item directly. The publish endpoint re-checks it, so this list is the
     * convenience and not the guard.
     *
     * @return array<int, array<string, mixed>>
     */
    private function publishTargets(): array
    {
        $user = Auth::user();

        return Project::query()
            ->where('status', Project::STATUS_ACTIVE)
            ->orderBy('name')
            ->get()
            ->filter(fn (Project $project) => $user->can('create', [WorkItem::class, $project]))
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'emoji' => $project->emoji,
                // Seeded here if the project has never had its states provisioned — publishing
                // into it is exactly the "first use" the provisioner exists for, and a picker
                // with nothing in it would make the starting state look unavailable rather
                // than unconfigured. Idempotent, and it leaves a customized set alone.
                'states' => $this->states->for($project)
                    ->map(fn (ProjectItemState $state) => [
                        'id' => $state->id,
                        'name' => $state->name,
                        'color' => $state->color,
                        // `group` is what wiStateIcon keys its glyph off — the name stays
                        // user-editable, the group does not, so the picker shows the same
                        // icon the work item lists show for that state.
                        'group' => $state->group,
                        'is_default' => $state->is_default,
                    ])->values()->all(),
            ])->values()->all();
    }
}
