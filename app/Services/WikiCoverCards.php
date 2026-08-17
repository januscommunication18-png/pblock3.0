<?php

namespace App\Services;

use App\Models\WikiCover;
use App\Models\WikiCoverSection;
use App\Models\WikiPage;
use Illuminate\Support\Collection;

/**
 * Cover Page section cards, resolved (docs/features/wiki-cover-page.md).
 *
 * One place answers "where does this card actually go?", for the settings panel and for the
 * reader alike. Two would be two answers, and the one that mattered would be whichever the
 * viewer happened to hit.
 *
 * Everything is resolved against `WikiReader::sections()`, which lists only ACTIVE pages. A
 * destination that has been deleted or archived is simply absent from it — which is how a broken
 * card is detected without asking every screen to remember to check.
 */
class WikiCoverCards
{
    public function __construct(private readonly WikiReader $reader) {}

    /**
     * The cards as SETTINGS sees them: every one, in order, the broken ones flagged rather than
     * dropped. An administrator cannot fix a card that has been hidden from them (FR-WC-032).
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    public function forSettings(WikiCover $cover, array $sections): array
    {
        $groups = $this->groupIndex($sections);
        $pages = $this->pageIndex($sections);

        return $this->rows($cover)
            ->map(function (WikiCoverSection $row) use ($groups, $pages) {
                $target = $row->destination_type === WikiCoverSection::TO_GROUP
                    ? ($groups[$row->destination_id] ?? null)
                    : ($pages[$row->destination_id] ?? null);

                return $row->toCard() + [
                    'destination_label' => $target
                        ? ($row->destination_type === WikiCoverSection::TO_GROUP ? $target['name'] : $target->title)
                        : null,
                    // A group that exists but holds nothing is as unusable as one that is gone:
                    // Explore would have nowhere to go.
                    'valid' => $target !== null
                        && ($row->destination_type === WikiCoverSection::TO_PAGE || $target['page'] !== null),
                ];
            })
            ->all();
    }

    /**
     * The cards a READER is shown.
     *
     * Configured cards take over from the derived ones entirely — a cover half-configured and
     * half-guessed would show one card somebody chose beside three the application invented.
     * Broken cards are absent, never rendered as a dead link.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    public function forReader(WikiCover $cover, array $sections): array
    {
        $rows = $this->rows($cover);

        if ($rows->isEmpty()) {
            return $this->reader->coverCards($sections);
        }

        $groups = $this->groupIndex($sections);
        $pages = $this->pageIndex($sections);

        return $rows
            ->map(function (WikiCoverSection $row) use ($groups, $pages) {
                if ($row->destination_type === WikiCoverSection::TO_GROUP) {
                    $group = $groups[$row->destination_id] ?? null;

                    return $group && $group['page'] ? [
                        'title' => $row->title,
                        'description' => $row->description ?: $group['short_description'],
                        'count' => $group['count'],
                        'page' => $group['page'],
                        'icon' => $row->toCard()['icon_key'],
                    ] : null;
                }

                $page = $pages[$row->destination_id] ?? null;

                return $page ? [
                    'title' => $row->title,
                    'description' => $row->description,
                    'count' => null,
                    'page' => $page,
                    'icon' => $row->toCard()['icon_key'],
                ] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * What the destination picker offers: this collection's sections and pages, nothing else.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function destinations(array $sections): array
    {
        $groups = [];
        $pages = [];

        foreach ($sections as $section) {
            // The ungrouped pseudo-section has no id — it is a heading the reader invents, not
            // a group anybody can point a card at.
            if ($section['id'] !== null) {
                $groups[] = ['value' => (string) $section['id'], 'label' => $section['name']];
            }

            foreach ($section['pages'] as $page) {
                $pages[] = ['value' => (string) $page->id, 'label' => $page->title];
            }

            foreach ($section['children'] ?? [] as $child) {
                $groups[] = ['value' => (string) $child['id'], 'label' => $section['name'].' → '.$child['name']];

                foreach ($child['pages'] as $page) {
                    $pages[] = ['value' => (string) $page->id, 'label' => $page->title];
                }
            }
        }

        return ['group' => $groups, 'page' => $pages];
    }

    /** @return Collection<int, WikiCoverSection> */
    private function rows(WikiCover $cover): Collection
    {
        if (! $cover->exists) {
            return collect();
        }

        return WikiCoverSection::query()
            ->where('wiki_cover_id', $cover->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * Every section by id — top level and nested — with what a card pointing at it would need.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    private function groupIndex(array $sections): array
    {
        $index = [];

        $add = function (array $section) use (&$index) {
            // Children count towards the parent: a card standing for a heading stands for
            // everything under it.
            $pages = collect($section['pages'])
                ->concat(collect($section['children'] ?? [])->flatMap(fn (array $c) => $c['pages']));

            $index[(int) $section['id']] = [
                'name' => $section['name'],
                'short_description' => $section['short_description'],
                'count' => $pages->count(),
                'page' => $pages->first(),
            ];
        };

        foreach ($sections as $section) {
            if ($section['id'] === null) {
                continue;
            }

            $add($section);

            foreach ($section['children'] ?? [] as $child) {
                $add($child);
            }
        }

        return $index;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, WikiPage>
     */
    private function pageIndex(array $sections): array
    {
        return collect($sections)
            ->flatMap(fn (array $s) => collect($s['pages'])
                ->concat(collect($s['children'] ?? [])->flatMap(fn (array $c) => $c['pages'])))
            ->keyBy('id')
            ->all();
    }
}
