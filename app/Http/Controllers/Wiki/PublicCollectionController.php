<?php

namespace App\Http\Controllers\Wiki;

use App\Http\Controllers\Controller;
use App\Models\WikiCollection;
use App\Models\WikiCover;
use App\Models\Workspace;
use App\Services\WikiReader;
use App\Services\WorkspaceApps;
use Illuminate\Contracts\View\View;

/**
 * A published collection, on the open internet (docs/features/wiki.md).
 *
 * No `auth`, and no tenancy middleware: whoever follows this link is a stranger, and the
 * workspace is identified by the URL rather than by a session. Everything is looked up inside
 * that workspace's own context, so nothing here can reach another one's rows.
 *
 * Everything that is not published is a 404 rather than a 403. A 403 confirms the address
 * belongs to something real, which is exactly what an unpublished page should not tell the
 * internet.
 */
class PublicCollectionController extends Controller
{
    public function __construct(
        private readonly WorkspaceApps $apps,
        private readonly WikiReader $reader,
    ) {}

    /** GET /{workspace}/{slug} */
    public function show(string $workspaceSlug, string $slug): View
    {
        $workspace = Workspace::query()->where('slug', $workspaceSlug)->first();

        abort_unless($workspace && $this->apps->isEnabled($workspace, 'wiki'), 404);

        [$collection, $sections, $current, $cover, $onCover, $firstPage, $coverCards] = $workspace->run(function () use ($slug) {
            $collection = WikiCollection::query()
                ->active()
                ->where('public_slug', $slug)
                ->first();

            // Published, and public inside the workspace too — a collection made private after
            // publishing must stop serving, whatever its status column still says.
            if (! $collection || ! $collection->isPublished() || $collection->isPrivate()) {
                return [null, [], null, null, false, null, []];
            }

            $sections = $this->reader->sections($collection);
            $cover = WikiCover::forCollection($collection);

            // The same landing rule Preview follows — what was previewed is what is published.
            $pageId = (int) request('page') ?: null;
            $onCover = $cover->is_enabled && $pageId === null;

            return [
                $collection,
                $sections,
                $onCover ? null : $this->reader->current($sections, $pageId),
                $cover,
                $onCover,
                // Where the cover sends somebody who has not chosen a page.
                $this->reader->current($sections, null),
                $onCover ? $this->reader->coverCards($sections) : [],
            ];
        });

        abort_unless($collection, 404);

        // The same template Preview renders — what was previewed is what is published.
        return view('wiki.reader', [
            'workspace' => $workspace,
            'collection' => $collection,
            'sections' => $sections,
            'current' => $current,
            'cover' => $cover->toCard(),
            'onCover' => $onCover,
            'coverUrl' => url('/'.$workspaceSlug.'/'.$slug),
            'firstPage' => $firstPage,
            'coverCards' => $coverCards,
            'outline' => $this->reader->outline($current?->content),
            'preview' => false,
            'publicUrl' => null,
            'pageUrl' => fn ($page) => url('/'.$workspaceSlug.'/'.$slug).'?page='.$page->id,
            'pageTree' => fn ($pages) => $this->reader->tree($pages),
        ]);
    }
}
