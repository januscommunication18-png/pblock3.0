<?php

namespace App\Http\Controllers\Wiki;

use App\Http\Controllers\Controller;
use App\Models\WikiCollectionGuest;
use App\Models\WikiCover;
use App\Services\WikiReader;
use App\Services\WorkspaceApps;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;

/**
 * The guest's door (docs/features/wiki-external-guests.md).
 *
 * No auth and no tenancy middleware: whoever follows this link has no account at all. The token
 * says which workspace and which collection, and everything is then read inside that workspace's
 * own context, so nothing here can reach another one's rows.
 *
 * EVERY failure is a 404 — unknown token, revoked guest, archived collection, Wiki switched off.
 * A 403 would confirm the address belongs to something real, which is exactly what a link that
 * has been withdrawn must not tell whoever is holding it.
 */
class WikiGuestController extends Controller
{
    public function __construct(
        private readonly WorkspaceApps $apps,
        private readonly WikiReader $reader,
    ) {}

    /** GET /wiki/guest/{token} */
    public function show(string $token, ?string $pageParam = null): View
    {
        // The row is resolved on EVERY request, not once at the start of a session: a removed
        // guest must lose access on their next page load, or revocation is not revocation.
        $guest = WikiCollectionGuest::findByToken($token);

        abort_unless($guest, 404);

        $workspace = $guest->workspace;

        abort_unless($workspace && $this->apps->isEnabled($workspace, 'wiki'), 404);

        [$collection, $sections, $current, $cover, $onCover, $firstPage, $coverCards] =
            $workspace->run(function () use ($guest) {
                $collection = $guest->collection;

                /*
                 * Publication is NOT consulted: the invitation is the authorization, which is
                 * the whole point — a draft can be shared with one client without going on the
                 * internet first.
                 *
                 * Archiving still wins, though. Retired cannot mean "retired, except for the
                 * client" (WIKI-D5).
                 */
                if (! $collection || $collection->archived_at !== null) {
                    return [null, [], null, null, false, null, []];
                }

                $sections = $this->reader->sections($collection);
                $cover = WikiCover::forCollection($collection);

                $pageId = (int) request('page') ?: null;
                $onCover = $cover->is_enabled && $pageId === null;

                return [
                    $collection,
                    $sections,
                    $onCover ? null : $this->reader->current($sections, $pageId),
                    $cover,
                    $onCover,
                    $this->reader->current($sections, null),
                    $onCover ? $this->reader->coverCards($sections) : [],
                ];
            });

        abort_unless($collection, 404);

        // "I sent it — did they read it?" is the question the Access list actually gets asked.
        $guest->forceFill(['last_seen_at' => now()])->saveQuietly();

        Log::info('wiki.guest.opened', [
            'collection' => $collection->id, 'email' => $guest->email,
        ]);

        $base = route('wiki.guest', ['token' => $token]);

        // The same template Preview and the published page render — what was previewed is what
        // the guest sees.
        return view('wiki.reader', [
            'workspace' => $workspace,
            'collection' => $collection,
            'sections' => $sections,
            'current' => $current,
            'cover' => $cover->toCard(),
            'onCover' => $onCover,
            'coverUrl' => $base,
            'firstPage' => $firstPage,
            'coverCards' => $coverCards,
            'outline' => $this->reader->outline($current?->content),
            'preview' => false,
            'guest' => $guest->name,
            'publicUrl' => null,
            'pageUrl' => fn ($page) => $base.'?page='.$page->id,
            'pageTree' => fn ($pages) => $this->reader->tree($pages),
        ]);
    }
}
