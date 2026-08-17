<?php

namespace App\Services;

use App\Models\WikiCollection;
use App\Models\WikiCollectionGroup;
use App\Models\WikiPage;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * What the reading layout needs (docs/features/wiki.md).
 *
 * Shared by the authenticated Preview and the published public page, so the two cannot show a
 * different table of contents for the same collection — the same reason they share one Blade
 * template rather than two that look alike today.
 */
class WikiReader
{
    /**
     * The collection's pages, arranged into the sections the navigation draws.
     *
     * Ungrouped pages come LAST and only when there are any: a section headed "Ungrouped" above
     * the real ones would be the first thing a reader saw, and it is the least interesting.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sections(WikiCollection $collection): array
    {
        $pages = WikiPage::query()
            ->where('wiki_collection_id', $collection->id)
            ->active()
            ->orderBy('position')
            ->get();

        $groups = WikiCollectionGroup::query()
            ->where('wiki_collection_id', $collection->id)
            ->orderBy('position')
            ->get();

        // Sub-groups are rendered UNDER their parent, so only the top level drives the outer
        // loop. A flat list would put "Installation" beside "Getting started" in the nav and
        // lose the one thing nesting was for.
        $card = fn (WikiCollectionGroup $g) => [
            'id' => $g->id,
            'name' => $g->name,
            'label' => $g->label,
            'short_description' => $g->short_description,
            'pages' => $pages->where('wiki_group_id', $g->id)->values(),
        ];

        $sections = $groups
            ->whereNull('parent_id')
            ->map(fn (WikiCollectionGroup $g) => $card($g) + [
                'children' => $groups->where('parent_id', $g->id)->map($card)->values()->all(),
            ])
            ->values()
            ->all();

        $ungrouped = $pages->whereNull('wiki_group_id')->values();

        if ($ungrouped->isNotEmpty()) {
            $sections[] = [
                'id' => null,
                // "Pages" rather than "Ungrouped": a reader does not care how the authors filed
                // things, only what there is to read.
                'name' => $groups->isEmpty() ? 'Pages' : 'More',
                'label' => null,
                'short_description' => null,
                'pages' => $ungrouped,
                'children' => [],
            ];
        }

        return $sections;
    }

    /**
     * One section's pages, arranged the way they were nested.
     *
     * `sections()` keeps its list FLAT and this is derived from it, rather than nesting in
     * place: `current()`, the cover cards and the destination picker all walk that list, and a
     * shape change there would have quietly dropped every sub-page from all three. Nesting is a
     * navigation concern, so it is computed where the navigation is drawn.
     *
     * @param  iterable<int, WikiPage>  $pages  in position order
     * @return array<int, array{page: WikiPage, children: array<int, mixed>}>
     */
    public function tree(iterable $pages): array
    {
        $pages = collect($pages);
        $present = $pages->pluck('id')->map(fn ($id) => (int) $id)->all();

        $byParent = [];

        foreach ($pages as $page) {
            /*
             * A page whose parent is not in this section is drawn at the TOP level rather than
             * dropped. A sub-page inherits its parent's group, so this should not happen — but
             * if it ever does, losing the page from the navigation entirely is far worse than
             * showing it a level too high.
             */
            $parent = in_array((int) $page->parent_id, $present, true) ? (int) $page->parent_id : 0;
            $byParent[$parent][] = $page;
        }

        $build = function (int $parent) use (&$build, $byParent): array {
            return collect($byParent[$parent] ?? [])
                ->map(fn (WikiPage $page) => ['page' => $page, 'children' => $build((int) $page->id)])
                ->all();
        };

        return $build(0);
    }

    /**
     * The cover's section cards, derived from what the collection already contains
     * (docs/features/wiki-cover-page.md).
     *
     * A cover whose cards all have to be configured by hand starts as a heading over an empty
     * grid, which reads as a broken feature rather than a new one. So the collection's own
     * arrangement IS the grid: a card per section, or — when nobody has made sections yet — a
     * card per page. Configured cards will take over from these in the slice that builds them.
     *
     * A card that leads nowhere is not drawn. An empty section is a heading somebody made in
     * advance, not a destination.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    public function coverCards(array $sections): array
    {
        $realSections = array_values(array_filter($sections, fn (array $s) => $s['id'] !== null));

        // Nobody has made sections: the pages themselves are what there is to offer.
        if ($realSections === []) {
            return collect($sections)
                ->flatMap(fn (array $s) => collect($s['pages']))
                ->map(fn (WikiPage $page) => [
                    'title' => $page->title,
                    'description' => $this->excerpt($page->content),
                    'count' => null,
                    'page' => $page,
                ])
                ->all();
        }

        // Ungrouped pages keep their place at the end, under the name `sections()` gave them —
        // a reader does not care how the authors filed things.
        return collect($sections)
            ->map(function (array $section) {
                // Children included: a section's card stands for everything under its heading,
                // and a count that ignored its sub-sections would understate it.
                $pages = collect($section['pages'])
                    ->concat(collect($section['children'] ?? [])->flatMap(fn (array $c) => $c['pages']));

                if ($pages->isEmpty()) {
                    return null;
                }

                return [
                    'title' => $section['name'],
                    'description' => $section['short_description'],
                    'count' => $pages->count(),
                    'page' => $pages->first(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * A card's description when the section has none: the opening of the page it leads to.
     *
     * Every tag becomes a SPACE rather than nothing. `strip_tags()` alone welds the last word of
     * a heading to the first word of the paragraph under it — "EscalatingRing the on-call".
     */
    private function excerpt(?string $html): ?string
    {
        $text = Str::of((string) preg_replace('/<[^>]*>/', ' ', (string) $html))->squish();

        return $text->isEmpty() ? null : (string) $text->limit(120);
    }

    /**
     * Give every heading an id, and list them for the "On this page" column.
     *
     * Done on the SERVER, not by walking the DOM afterwards: an anchor has to work on the
     * first paint, and a link to `#when-to-escalate` that only becomes real once a script has
     * run is a link that fails exactly when somebody follows it from elsewhere.
     *
     * H1 through H4 (docs/features/wiki-cover-page.md, FR-WC-021).
     *
     * The requirement asks for H2–H4 on the grounds that H1 is the page title and should not be
     * repeated. It is not, here: the title is rendered from `$current->title` and never appears
     * in the body, so an H1 inside the document is a section heading like any other — and every
     * page written before this feature used them. Excluding H1 would empty the column on exactly
     * the documents that most need it.
     *
     * @return array{html: string, toc: array<int, array{id: string, text: string, level: int}>}
     */
    public function outline(?string $html): array
    {
        $html = (string) $html;

        if (trim($html) === '') {
            return ['html' => '', 'toc' => []];
        }

        $doc = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // The meta forces UTF-8; without it DOMDocument reads the bytes as ISO-8859-1 and
        // every accented character in somebody's documentation comes back mangled.
        $doc->loadHTML(
            '<?xml encoding="utf-8" ?><div id="pb-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $toc = [];
        $used = [];

        foreach (['h1', 'h2', 'h3', 'h4'] as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
                $text = trim($node->textContent);

                if ($text === '') {
                    continue;
                }

                $id = $node->getAttribute('id') ?: Str::slug($text);
                $id = $id !== '' ? $id : 'section';

                // Two headings called the same thing is ordinary in documentation; two
                // elements with the same id is not, and the second anchor would never work.
                $base = $id;
                $n = 2;
                while (isset($used[$id])) {
                    $id = $base.'-'.$n++;
                }
                $used[$id] = true;

                $node->setAttribute('id', $id);

                $toc[] = [
                    'id' => $id,
                    'text' => $text,
                    'level' => (int) substr($tag, 1),
                    // Where it sits in the document, so the column reads in reading order
                    // rather than all the H1s and then all the H2s.
                    'order' => $this->documentOrder($node),
                ];
            }
        }

        usort($toc, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        $root = $doc->getElementById('pb-root');
        $out = '';

        foreach ($root?->childNodes ?? [] as $child) {
            $out .= $doc->saveHTML($child);
        }

        return [
            'html' => $out,
            'toc' => array_map(fn (array $e) => Arr::except($e, 'order'), $toc),
        ];
    }

    /** A sortable position for a node, from its place in the tree. */
    private function documentOrder(\DOMNode $node): int
    {
        $i = 0;

        foreach ($node->ownerDocument->getElementsByTagName('*') as $each) {
            if ($each === $node) {
                return $i;
            }

            $i++;
        }

        return $i;
    }

    /**
     * The page being read: the one asked for, else the first there is.
     *
     * @param  array<int, array<string, mixed>>  $sections
     */
    public function current(array $sections, ?int $pageId): ?WikiPage
    {
        // Children included, and in the order the nav draws them: a page filed in a sub-group
        // is still a page somebody can link to, and flattening only the top level would send
        // `?page=<sub-group page>` silently back to the first page in the collection.
        $all = collect($sections)->flatMap(fn (array $s) => collect($s['pages'])
            ->concat(collect($s['children'] ?? [])->flatMap(fn (array $c) => $c['pages'])));

        return ($pageId ? $all->firstWhere('id', $pageId) : null) ?? $all->first();
    }
}
