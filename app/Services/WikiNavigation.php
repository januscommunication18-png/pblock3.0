<?php

namespace App\Services;

use App\Http\Controllers\Wiki\WikiController;
use App\Models\User;
use App\Models\WikiCollection;
use Illuminate\Http\Request;

/**
 * What the Wiki's sidebar draws (docs/features/wiki.md).
 *
 * The collections list used to be a raw query inlined in `partials/wiki-nav.blade.php`, which had
 * two problems: it named private collections to people who would be refused on click, and it
 * could not be passed in from a controller — the partial rides along with the shared sidebar on
 * every Wiki screen, across four controllers, and each one remembering to supply it is exactly
 * the failure the workspace-switcher composer already documents.
 */
class WikiNavigation
{
    /**
     * The rows across the top, each knowing where it goes and whether you are on it.
     *
     * `collections` is dropped: the disclosure below carries that name, and two rows saying
     * "Collections" is one too many.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sectionsFor(Request $request): array
    {
        return collect(config('wiki.sections'))
            ->reject(fn (array $section) => $section['key'] === 'collections')
            ->map(fn (array $section) => [
                'label' => $section['label'],
                'icon' => $section['icon'],
                'blurb' => $section['blurb'],
                'href' => $section['key'] === 'home'
                    ? route('wiki.home')
                    : route('wiki.section', $section['key']),
                'active' => $section['key'] === 'home'
                    ? $request->routeIs('wiki.home')
                    : $request->routeIs('wiki.section') && $request->route('section') === $section['key'],
            ])
            ->values()
            ->all();
    }

    /**
     * The collections under the disclosure — permission-filtered, so the sidebar can never name
     * something the screen behind it would refuse.
     *
     * ACTIVE only. An archived collection has left navigation; the Archived row above is what
     * highlights while you are reading one, and putting it back in this list would undo the one
     * thing archiving does.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collectionsFor(User $user, Request $request): array
    {
        $open = $request->routeIs('wiki.collections.show')
            ? $request->route('collection')?->id
            : null;

        return WikiCollection::query()
            ->active()
            ->visibleTo($user)
            ->orderBy('position')
            ->get()
            ->map(fn (WikiCollection $collection) => [
                'name' => $collection->name,
                'private' => $collection->isPrivate(),
                'url' => route('wiki.collections.show', $collection),
                'active' => $open !== null && (int) $open === (int) $collection->id,
            ])
            ->all();
    }

    /** Whether a section key is one of the three lenses — for anything that needs to ask. */
    public function isLens(string $key): bool
    {
        return in_array($key, WikiController::LENSES, true);
    }
}
