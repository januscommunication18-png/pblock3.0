<?php

namespace App\Http\Controllers\Wiki;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wiki\StoreCollectionRequest;
use App\Models\WikiCollection;
use App\Services\WorkspaceApps;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The Wiki's list screens (docs/features/wiki.md) — Home, Shared, Private and Archived.
 *
 * One controller and one view for all four, because they answer the same question — WHICH
 * COLLECTIONS SHOULD I BE LOOKING AT? — from the same builder and the same payload. They differ
 * by a where clause. CollectionController is split off because a collection answers a different
 * question; these do not.
 */
class WikiController extends Controller
{
    /**
     * The sections of `config('wiki.sections')` that are a filtered list rather than a screen of
     * their own. `home` has its own route; `collections` IS home's list, and a second URL for it
     * would be two doors into one room — the same reason the sidebar skips that row.
     */
    public const LENSES = ['shared', 'private', 'archived'];

    public function home(WorkspaceApps $apps): View
    {
        return $this->screen($apps, 'home');
    }

    /** GET /wiki/{shared|private|archived} */
    public function section(WorkspaceApps $apps, string $section): View
    {
        return $this->screen($apps, $section);
    }

    /** POST /wiki/collections — create one (§"Collections"). */
    public function storeCollection(StoreCollectionRequest $request, WorkspaceApps $apps): JsonResponse
    {
        $workspace = Auth::user()->currentWorkspace;
        abort_unless($apps->isEnabled($workspace, 'wiki'), 404);

        WikiCollection::create($request->validated() + [
            'created_by' => Auth::id(),
            // Appended, so a new collection lands at the end of the order somebody has
            // already arranged rather than jumping to the top of it.
            'position' => (int) WikiCollection::query()->max('position') + 1,
        ]);

        $section = $request->query('section');
        $section = in_array($section, self::LENSES, true) ? $section : 'home';

        return response()->json([
            'ok' => true,
            // The grid for the screen they are actually on — creating a public collection from
            // Shared correctly leaves that grid empty…
            'collections' => $this->collections($section),
            // …and the sidebar still gains the row, because the sidebar is always the active
            // readable list whatever lens is being viewed.
            'sidebar' => $this->collections('home'),
            'message' => 'Collection created.',
        ]);
    }

    /** One screen, four lenses. */
    private function screen(WorkspaceApps $apps, string $section): View
    {
        $workspace = Auth::user()->currentWorkspace;

        // A workspace that has not enabled Wiki has no Wiki to reach.
        abort_unless($apps->isEnabled($workspace, 'wiki'), 404);

        $copy = $this->copy($section);

        return view('wiki.home', [
            'workspace' => $workspace,
            'section' => $section,
            'heading' => $copy['heading'],
            'bootstrap' => [
                'section' => $section,
                // The toolbar draws the title, so it needs both — same shape as the Projects
                // index, whose header this matches.
                'heading' => $copy['heading'],
                'icon' => $copy['icon'],
                'collections' => $this->collections($section),
                'emptyState' => $copy['empty'],
                'visibilities' => WikiCollection::visibilityOptions(),
                'endpoints' => [
                    'collections' => route('wiki.collections.store', ['section' => $section]),
                    'home' => route('wiki.home'),
                ],
            ],
        ]);
    }

    /**
     * The collections one lens shows.
     *
     * `visibleTo()` is applied on EVERY branch, including `private` where the creator always
     * passes it anyway. Applying it uniformly is what makes "every list is permission-filtered"
     * a property of the code rather than a claim about it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collections(string $section): array
    {
        $user = Auth::user();
        $query = WikiCollection::query()->visibleTo($user);

        match ($section) {
            // Private ones YOU made. Not a move — they are still under Collections too; this is
            // a second way of LOOKING at them, not a second place they live.
            'private' => $query->active()
                ->where('visibility', WikiCollection::VISIBILITY_PRIVATE)
                ->where('created_by', $user->id)
                ->orderBy('position'),

            // Private ones SOMEBODY ELSE made and put you on. Public ones are deliberately
            // absent: "shared with you" means a decision was made about you, and a public
            // collection is shared with nobody in particular.
            'shared' => $query->active()
                ->where('visibility', WikiCollection::VISIBILITY_PRIVATE)
                // `created_by` is nullable — the creator's account can be deleted — and SQL
                // drops NULL rows from `!= $id`, so the null branch is spelled out or those
                // collections vanish from the one list that should still carry them.
                ->where(fn (Builder $q) => $q
                    ->whereNull('created_by')
                    ->orWhere('created_by', '!=', $user->id))
                ->whereHas('members', fn (Builder $q) => $q->where('user_id', $user->id))
                ->orderBy('position'),

            // Retired, still readable. Ordered by WHEN it was retired rather than by `position`:
            // position arranges the things you navigate, and an archive is a history.
            'archived' => $query->archived()->orderByDesc('archived_at'),

            default => $query->active()->orderBy('position'),
        };

        return $query->get()
            ->map(fn (WikiCollection $c) => $c->toCard() + [
                'url' => route('wiki.collections.show', $c),
            ])
            ->all();
    }

    /**
     * What each screen calls itself, and what it says when it is empty.
     *
     * Home keeps the copy it has always had. The three lenses read `config('wiki.sections')`,
     * which is where those labels and blurbs were declared and until now only the sidebar used.
     *
     * @return array<string, mixed>
     */
    private function copy(string $section): array
    {
        if ($section === 'home') {
            return [
                'heading' => 'Wiki',
                // `folder`, not the sidebar's `house`: the toolbar names what this screen LISTS,
                // and the Projects index it matches carries a folder for the same reason.
                'icon' => 'folder',
                'empty' => [
                    'icon' => 'folder',
                    'title' => 'No collections yet',
                    'body' => 'A collection is a home for related pages — a handbook, a set of '
                        .'runbooks, the answers people keep asking for.',
                    'cta' => ['label' => 'Create a collection', 'visibility' => 'public'],
                ],
            ];
        }

        $declared = collect(config('wiki.sections'))->firstWhere('key', $section) ?? [];

        return [
            'heading' => $declared['label'] ?? ucfirst($section),
            'icon' => $declared['icon'] ?? 'folder',
            'empty' => match ($section) {
                'private' => [
                    'icon' => 'lock',
                    'title' => 'No private collections',
                    'body' => 'A private collection is open only to the people you invite. Make '
                        .'one when something is not for the whole workspace.',
                    // Opening the generic modal here and having it default to Public would be
                    // the screen contradicting itself.
                    'cta' => ['label' => 'Create a private collection', 'visibility' => 'private'],
                ],
                'shared' => [
                    'icon' => 'users',
                    'title' => 'Nothing shared with you',
                    'body' => 'When somebody invites you to a private collection it appears here. '
                        .'Collections the whole workspace can read are under Collections.',
                    // No create button: you cannot create your way into being invited.
                    'cta' => null,
                ],
                default => [
                    'icon' => 'box-archive',
                    'title' => 'Nothing archived',
                    'body' => 'Archiving retires a collection without deleting it. Its pages stay, '
                        .'and it can be restored from the collection’s ⋯ menu.',
                    'cta' => null,
                ],
            },
        ];
    }
}
