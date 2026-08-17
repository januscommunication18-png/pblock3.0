<?php

namespace App\Http\Controllers\Wiki;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wiki\StoreCollectionRequest;
use App\Models\User;
use App\Models\WikiCollection;
use App\Models\WikiCollectionGroup;
use App\Models\WikiCollectionMember;
use App\Models\WikiCover;
use App\Models\WikiLabel;
use App\Models\WikiPage;
use App\Models\WorkspaceMembership;
use App\Services\WikiReader;
use App\Services\WorkspaceApps;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * One collection, and who may open it (docs/features/wiki.md).
 *
 * Split from WikiController because the Wiki home and a collection answer different questions
 * and were already sharing nothing but the enabled check.
 */
class CollectionController extends Controller
{
    public function __construct(private readonly WorkspaceApps $apps) {}

    /** GET /wiki/collections/{collection} */
    public function show(WikiCollection $collection): View
    {
        $this->guard();

        // A private collection is only private if being unlisted is not the only thing
        // stopping people opening it.
        abort_unless($collection->openableBy(Auth::user()), 403);

        return view('wiki.collection', [
            'workspace' => Auth::user()->currentWorkspace,
            'collection' => $collection,
            'bootstrap' => $this->payload($collection),
        ]);
    }

    /**
     * PATCH /wiki/collections/{collection} — rename it, redescribe it, change who can see it.
     *
     * Turning a public collection private closes it to everybody not named on it, so this is
     * the same gate as managing access rather than the looser "can edit pages" one.
     */
    public function update(StoreCollectionRequest $request, WikiCollection $collection): JsonResponse
    {
        $this->guard();
        $this->guardManage($collection);

        $collection->fill($request->validated())->save();

        return response()->json([
            'ok' => true,
            'collection' => $this->payload($collection->fresh()),
            'message' => 'Collection updated.',
        ]);
    }

    /**
     * PATCH /wiki/collections/{collection}/status — draft, published, unpublished.
     *
     * A PRIVATE collection cannot be published. Publishing puts it on the open internet, and
     * "private" is a statement about who may read it — honouring both at once is not possible,
     * so the contradiction is refused rather than silently resolved one way.
     */
    public function updateStatus(Request $request, WikiCollection $collection): JsonResponse
    {
        $this->guard();
        $this->guardManage($collection);

        $data = $request->validate([
            'status' => ['required', Rule::in(WikiCollection::statuses())],
        ]);

        $status = $data['status'];

        if ($status === WikiCollection::STATUS_PUBLISHED) {
            abort_if(
                $collection->isPrivate(),
                422,
                'A private collection cannot be published. Make it public first.',
            );

            abort_if(
                $collection->public_slug === null,
                422,
                'Generate the public URL before publishing.',
            );
        }

        $collection->forceFill([
            'status' => $status,
            'published_at' => $status === WikiCollection::STATUS_PUBLISHED
                ? ($collection->published_at ?? now())
                : $collection->published_at,
        ])->save();

        return response()->json([
            'ok' => true,
            'collection' => $this->payload($collection->fresh()),
            'message' => match ($status) {
                WikiCollection::STATUS_PUBLISHED => 'Collection published.',
                WikiCollection::STATUS_UNPUBLISHED => 'Collection unpublished.',
                default => 'Collection moved back to draft.',
            },
        ]);
    }

    /**
     * POST /wiki/collections/{collection}/public-url — mint the address, once.
     *
     * Deliberately not idempotent-with-a-new-value: an address people bookmark, paste into
     * documents and send to customers cannot change underneath them, so the second call is
     * refused rather than quietly issuing a new one. There is no rename, by design.
     */
    public function generatePublicUrl(WikiCollection $collection): JsonResponse
    {
        $this->guard();
        $this->guardManage($collection);

        abort_if(
            $collection->public_slug !== null,
            422,
            'This collection already has a public URL, and it cannot be changed.',
        );

        $collection->forceFill(['public_slug' => $this->mintSlug($collection)])->save();

        return response()->json([
            'ok' => true,
            'collection' => $this->payload($collection->fresh()),
            'message' => 'Public URL generated.',
        ]);
    }

    /**
     * A slug from the name, made unique within the workspace.
     *
     * From the NAME rather than a random string: a readable address is the one people trust
     * enough to click. The counter only appears when it has to.
     */
    private function mintSlug(WikiCollection $collection): string
    {
        $base = Str::slug($collection->name) ?: 'collection';
        $slug = $base;
        $n = 2;

        while (WikiCollection::query()->where('public_slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /**
     * GET /wiki/collections/{collection}/preview — how it reads to somebody opening it.
     *
     * The same template the published page uses, so what is previewed is what would be
     * published rather than an approximation of it.
     */
    public function preview(WikiCollection $collection, WikiReader $reader): View
    {
        $this->guard();
        abort_unless($collection->openableBy(Auth::user()), 403);

        $sections = $reader->sections($collection);
        $cover = WikiCover::forCollection($collection);

        /*
         * WCOV-3 — with a cover enabled, arriving without a page in the address opens the front
         * door rather than whichever page happens to sort first. `?page=` still goes straight
         * to that page: a link somebody sent must not be intercepted by a landing screen.
         */
        $pageId = (int) request('page') ?: null;
        $onCover = $cover->is_enabled && $pageId === null;
        $current = $onCover ? null : $reader->current($sections, $pageId);

        return view('wiki.reader', [
            'workspace' => Auth::user()->currentWorkspace,
            'collection' => $collection,
            'sections' => $sections,
            'current' => $current,
            'cover' => $cover->toCard(),
            'onCover' => $onCover,
            'coverUrl' => route('wiki.collections.preview', $collection),
            // Where the cover sends somebody who has not chosen a page. The reader's own
            // traversal, not a second one written for the landing screen.
            'firstPage' => $reader->current($sections, null),
            'coverCards' => $onCover ? $reader->coverCards($sections) : [],
            'outline' => $reader->outline($current?->content),
            'preview' => true,
            'publicUrl' => $collection->public_slug
                ? url('/'.tenant('slug').'/'.$collection->public_slug)
                : null,
            'pageUrl' => fn ($page) => route('wiki.collections.preview', $collection).'?page='.$page->id,
            // Nesting, for the navigation only — see WikiReader::tree().
            'pageTree' => fn ($pages) => $reader->tree($pages),
        ]);
    }

    /**
     * PATCH /wiki/collections/{collection}/archive — retire it, or bring it back.
     *
     * §"Archive instead of delete". Archiving takes a collection out of the lists and leaves
     * everything in it exactly where it was. The same endpoint restores it: a one-way door would
     * be a delete wearing a gentler word, and the Archived view is not built yet — without a way
     * back from here, archiving would be the last thing anybody could do to a collection.
     */
    public function archive(Request $request, WikiCollection $collection): JsonResponse
    {
        $this->guard();
        $this->guardManage($collection);

        $archived = $request->boolean('archived', true);

        $collection->forceFill(['archived_at' => $archived ? now() : null])->save();

        return response()->json([
            'ok' => true,
            'collection' => $collection->fresh()->toCard() + ['owner' => $this->person($collection->creator)],
            'message' => $archived ? 'Collection archived.' : 'Collection restored.',
        ]);
    }

    /**
     * DELETE /wiki/collections/{collection} — and everything inside it.
     *
     * The one destructive action in the Wiki. Pages, groups, members and the cover follow by
     * foreign key, and none of it comes back. Archiving is the reversible answer and the menu
     * offers it first; this is behind a dialog that asks for the collection's name rather than
     * a click, the same as deleting a project.
     */
    public function destroy(WikiCollection $collection): JsonResponse
    {
        $this->guard();
        $this->guardManage($collection);

        $collection->delete();

        return response()->json([
            'ok' => true,
            // The screen described something that no longer exists; it cannot stay on it.
            'redirect' => route('wiki.home'),
            'message' => 'Collection deleted.',
        ]);
    }

    /** POST /wiki/collections/{collection}/members — invite somebody (§"Give people the right level of access"). */
    public function storeMember(Request $request, WikiCollection $collection): JsonResponse
    {
        $this->guard();
        $this->guardManage($collection);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'permission' => ['required', Rule::in(WikiCollectionMember::permissions())],
        ]);

        // Only somebody already in the workspace. Sharing a collection is not a way to let a
        // stranger in through a side door.
        abort_unless($this->workspaceMemberIds()->contains((int) $data['user_id']), 422,
            'That person is not a member of this workspace.');

        WikiCollectionMember::updateOrCreate(
            ['wiki_collection_id' => $collection->id, 'user_id' => (int) $data['user_id']],
            ['permission' => $data['permission'], 'invited_by' => Auth::id()],
        );

        return response()->json([
            'ok' => true,
            'collection' => $this->payload($collection->fresh()),
            'message' => 'Access granted.',
        ]);
    }

    /** DELETE /wiki/collections/{collection}/members/{member} */
    public function destroyMember(WikiCollection $collection, WikiCollectionMember $member): JsonResponse
    {
        $this->guard();
        $this->guardManage($collection);
        abort_unless((int) $member->wiki_collection_id === (int) $collection->id, 404);

        $member->delete();

        return response()->json([
            'ok' => true,
            'collection' => $this->payload($collection->fresh()),
            'message' => 'Access removed.',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(WikiCollection $collection): array
    {
        $members = $collection->members()->with('user')->get()
            ->map(fn (WikiCollectionMember $m) => [
                'id' => $m->id,
                'permission' => $m->permission,
                'permission_label' => WikiCollectionMember::label($m->permission),
                'user' => $this->person($m->user),
            ])->values()->all();

        $taken = collect($members)->pluck('user.id')->push((int) $collection->created_by);

        return [
            'collection' => $collection->toCard() + [
                'owner' => $this->person($collection->creator),
            ],
            'members' => $members,
            // Everyone still addable, so the picker never offers somebody who is already on it.
            'candidates' => User::query()
                ->whereIn('id', $this->workspaceMemberIds())
                ->whereNotIn('id', $taken)
                ->orderBy('full_name')
                ->get()
                ->map(fn (User $u) => $this->person($u))
                ->all(),
            'visibilities' => WikiCollection::visibilityOptions(),
            'permissions' => [
                ['value' => WikiCollectionMember::PERMISSION_READ, 'label' => 'Read only',
                    'desc' => 'Open and read pages, but not change them.'],
                ['value' => WikiCollectionMember::PERMISSION_EDIT, 'label' => 'Can edit',
                    'desc' => 'Create and update pages in this collection.'],
            ],
            'canManage' => $this->canManage($collection),
            'canEdit' => $this->canEdit($collection),
            'pages' => WikiPage::query()
                ->where('wiki_collection_id', $collection->id)
                ->active()
                ->withCount('children')
                ->with(['creator', 'editor', 'labels', 'parent'])
                ->orderBy('position')
                ->get()
                ->map(fn (WikiPage $p) => $p->toCard() + [
                    'url' => route('wiki.pages.show', ['collection' => $collection->id, 'page' => $p->id]),
                ])
                ->all(),
            'groups' => WikiCollectionGroup::query()
                ->where('wiki_collection_id', $collection->id)
                ->orderBy('position')
                ->get()
                ->map(fn (WikiCollectionGroup $g) => $g->toCard())
                ->all(),
            // The Cover Page, and the two layout choices its panel offers
            // (docs/features/wiki-cover-page.md). Sent with the screen rather than fetched by
            // the card, like the groups and pages beside it.
            'cover' => WikiCover::forCollection($collection)->toCard(),
            'coverAlignments' => [
                ['value' => WikiCover::ALIGN_LEFT, 'label' => 'Left align', 'icon' => 'align-left',
                    'desc' => 'Held to the left of the reading area.'],
                ['value' => WikiCover::ALIGN_CENTER, 'label' => 'Center', 'icon' => 'align-center',
                    'desc' => 'Centred — easiest to read at length.'],
                ['value' => WikiCover::ALIGN_RIGHT, 'label' => 'Right align', 'icon' => 'align-right',
                    'desc' => 'Held to the right, clear of the contents rail.'],
            ],
            'coverLayouts' => [
                ['value' => WikiCover::LAYOUT_AUTO, 'label' => 'Auto', 'icon' => 'grid',
                    'desc' => 'As many columns as the screen has room for.'],
                ['value' => WikiCover::LAYOUT_TWO, 'label' => '2 columns', 'icon' => 'columns',
                    'desc' => 'Always two, until the screen is too narrow.'],
                ['value' => WikiCover::LAYOUT_THREE, 'label' => '3 columns', 'icon' => 'table',
                    'desc' => 'Always three, until the screen is too narrow.'],
            ],
            // Every label the workspace has, for the Edit dialog's picker.
            'labels' => WikiLabel::query()->orderBy('position')->get()
                ->map(fn (WikiLabel $l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])
                ->all(),
            'statuses' => [
                ['value' => WikiCollection::STATUS_DRAFT, 'label' => 'Draft',
                    'desc' => 'Only people inside the workspace can reach it.'],
                ['value' => WikiCollection::STATUS_PUBLISHED, 'label' => 'Published',
                    'desc' => 'Live at its public URL for anyone with the link.'],
                ['value' => WikiCollection::STATUS_UNPUBLISHED, 'label' => 'Unpublished',
                    'desc' => 'Taken down. The URL is kept and still belongs to this collection.'],
            ],
            'publicUrl' => $collection->public_slug
                ? url('/'.tenant('slug').'/'.$collection->public_slug)
                : null,
            'previewUrl' => route('wiki.collections.preview', $collection),
            'endpoints' => [
                'collection' => route('wiki.collections.update', $collection),
                'status' => route('wiki.collections.status', $collection),
                'archive' => route('wiki.collections.archive', $collection),
                'deleteCollection' => route('wiki.collections.destroy', $collection),
                'publicUrl' => route('wiki.collections.public-url', $collection),
                'pages' => route('wiki.pages.store', $collection),
                'pageDetails' => route('wiki.pages.details', ['collection' => $collection->id, 'page' => '__ID__']),
                'pageRemove' => route('wiki.pages.destroy', ['collection' => $collection->id, 'page' => '__ID__']),
                'pagesReorder' => route('wiki.pages.reorder', $collection),
                'cover' => route('wiki.cover.update', $collection),
                'groups' => route('wiki.groups.store', $collection),
                'group' => route('wiki.groups.update', ['collection' => $collection->id, 'group' => '__ID__']),
                'groupsReorder' => route('wiki.groups.reorder', $collection),
                'pageGroup' => route('wiki.pages.group', ['collection' => $collection->id, 'page' => '__ID__']),
                'members' => route('wiki.collections.members.store', $collection),
                'member' => route('wiki.collections.members.destroy', ['collection' => $collection->id, 'member' => '__ID__']),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function person(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->full_name ?: $user->email,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
            'initial' => mb_strtoupper(mb_substr($user->full_name ?: $user->email, 0, 1)),
        ];
    }

    private function workspaceMemberIds(): Collection
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', tenant('id'))
            ->where('status', 'active')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);
    }

    /** Who may change who can see this: the person who made it, or whoever runs the workspace. */
    private function canManage(WikiCollection $collection): bool
    {
        return $collection->manageableBy(Auth::user());
    }

    /**
     * Who may add pages here.
     *
     * The same rule PageController enforces — the creator, whoever runs the workspace, or an
     * invitation carrying `edit`. Shown from here so the screen never offers a button the
     * endpoint behind it would refuse.
     */
    private function canEdit(WikiCollection $collection): bool
    {
        return $collection->writableBy(Auth::user());
    }

    private function guardManage(WikiCollection $collection): void
    {
        abort_unless($this->canManage($collection), 403);
    }

    private function guard(): void
    {
        abort_unless($this->apps->isEnabled(Auth::user()->currentWorkspace, 'wiki'), 404);
    }
}
