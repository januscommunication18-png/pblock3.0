<?php

namespace App\Services\Search;

use App\Models\User;
use App\Models\WikiCollection;
use App\Models\WikiPage;
use App\Models\WorkItem;
use Illuminate\Support\Facades\Log;

/**
 * Global search across the workspace (docs/features/global-search.md).
 *
 * Slice 1 searches work items only. Each further object type becomes one more group built the
 * same way — engine query, reach filter, hydrate, shape — which is why the grouping lives here
 * rather than in the controller.
 *
 * Everything goes through Scout, so the engine is an env choice: `database` locally,
 * `meilisearch` in production (decision G1/G3). No code here knows which.
 */
class GlobalSearch
{
    /** Results shown per group in the palette (§7, §18). */
    public const PER_GROUP = 5;

    /**
     * Asked of the engine before filtering.
     *
     * Larger than PER_GROUP because the assigned-only rule is applied during hydration (G6):
     * the engine cannot know about it, so its top 5 could be 5 rows the user may not open,
     * leaving an empty group under a heading that says otherwise.
     */
    private const OVER_FETCH = 40;

    /** Shortest query worth running (§6.1). */
    public const MIN_CHARS = 2;

    /**
     * @return array{groups: array<int, array<string, mixed>>, error: bool}
     */
    public function run(User $user, ?string $tenantId, string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < self::MIN_CHARS) {
            return ['groups' => [], 'error' => false];
        }

        try {
            $reach = new SearchReach($user, $tenantId);

            $items = $this->workItems($reach, $query);
            $wiki = $this->wiki($reach, $query);
        } catch (\Throwable $e) {
            // The search engine being down is not a zero-result search (§13.4 vs §13.5), and
            // the two must not look alike to the user.
            Log::error('Global search failed', [
                'driver' => config('scout.driver'),
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return ['groups' => [], 'error' => true];
        }

        $groups = [];

        if ($items !== []) {
            $groups[] = ['key' => 'work_items', 'label' => 'Work Items', 'results' => $items];
        }

        if ($wiki !== []) {
            $groups[] = ['key' => 'wiki', 'label' => 'Wiki', 'results' => $wiki];
        }

        return ['groups' => $groups, 'error' => false];
    }

    /** @return array<int, array<string, mixed>> */
    private function workItems(SearchReach $reach, string $query): array
    {
        // No reachable project means no possible result. Skipping the engine here is not just
        // a saving: an unfiltered search would otherwise be one forgotten `whereIn` away from
        // returning another workspace's items.
        if ($reach->isEmpty()) {
            return [];
        }

        $found = WorkItem::search($query)
            ->whereIn('project_id', $reach->projectIds())
            ->take(self::OVER_FETCH)
            ->query(fn ($builder) => $reach->constrain($builder)->with([
                'project:id,name,identifier',
                'state:id,name',
                'assignees:id,full_name',
            ]))
            ->get();

        return $found
            ->sortByDesc(fn (WorkItem $item) => $this->score($item, $query))
            ->take(self::PER_GROUP)
            ->map(fn (WorkItem $item) => $this->shape($item, $query))
            ->values()
            ->all();
    }

    /**
     * Wiki collections and their pages, as one group (§5, §8.2).
     *
     * Both types together rather than two groups: to somebody searching, "the wiki" is one
     * place, and a collection and a page inside it are the same kind of answer at different
     * depths. They are distinguished on the card by their context line, not by a heading.
     *
     * @return array<int, array<string, mixed>>
     */
    private function wiki(SearchReach $reach, string $query): array
    {
        $collectionIds = $reach->wikiCollectionIds();

        if ($collectionIds === []) {
            return [];
        }

        $pages = WikiPage::search($query)
            ->whereIn('wiki_collection_id', $collectionIds)
            ->take(self::OVER_FETCH)
            ->query(fn ($builder) => $builder->whereNull('archived_at')->with('collection:id,name'))
            ->get()
            ->map(fn (WikiPage $page) => [
                'type' => 'wiki_page',
                'id' => (int) $page->id,
                'title' => (string) $page->title,
                'context' => $page->collection?->name,
                'snippet' => $this->snippet($page->plainContent(), $query),
                'updated_at' => optional($page->updated_at)->toIso8601String(),
                'url' => route('wiki.collections.show', ['collection' => $page->wiki_collection_id])
                    .'?page='.$page->id,
                '_score' => $this->textScore((string) $page->title, $page->plainContent(), $query),
            ]);

        $collections = WikiCollection::search($query)
            ->whereIn('id', $collectionIds)
            ->take(self::OVER_FETCH)
            ->get()
            ->map(fn (WikiCollection $c) => [
                'type' => 'wiki_collection',
                'id' => (int) $c->id,
                'title' => (string) $c->name,
                'context' => 'Collection',
                'snippet' => $this->snippet((string) $c->description, $query),
                'updated_at' => optional($c->updated_at)->toIso8601String(),
                'url' => route('wiki.collections.show', ['collection' => $c->id]),
                '_score' => $this->textScore((string) $c->name, (string) $c->description, $query),
            ]);

        return $pages->concat($collections)
            ->sortByDesc('_score')
            ->take(self::PER_GROUP)
            ->map(function (array $row) {
                unset($row['_score']);

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * Ranking for text-only records (§6.2) — the same ladder work items use, minus the
     * identifier rungs, which a wiki page does not have.
     */
    private function textScore(string $title, string $body, string $query): int
    {
        $q = mb_strtolower($query);
        $t = mb_strtolower($title);

        if ($t === $q) {
            return 90;
        }

        if (str_starts_with($t, $q)) {
            return 80;
        }

        if (str_contains($t, $q)) {
            return 70;
        }

        return str_contains(mb_strtolower($body), $q) ? 50 : 10;
    }

    /**
     * Ranking (§6.2), applied over the engine's own relevance.
     *
     * The engine ranks text; it does not know that "PB-168" typed in full is somebody naming a
     * record rather than describing one. An exact identifier match therefore outranks
     * everything, which is the single most common way this box gets used.
     */
    private function score(WorkItem $item, string $query): int
    {
        $q = mb_strtolower($query);
        $identifier = mb_strtolower((string) $item->identifier);
        $title = mb_strtolower((string) $item->title);

        if ($identifier === $q || $identifier === ltrim($q, '#')) {
            return 100;
        }

        if ($title === $q) {
            return 90;
        }

        if (str_starts_with($title, $q)) {
            return 80;
        }

        if (str_contains($title, $q)) {
            return 70;
        }

        if (str_contains($identifier, $q)) {
            return 60;
        }

        return str_contains(mb_strtolower($item->plainDescription()), $q) ? 50 : 10;
    }

    /** The result card's data (§8.1). */
    private function shape(WorkItem $item, string $query): array
    {
        return [
            'type' => 'work_item',
            'id' => (int) $item->id,
            'identifier' => (string) $item->identifier,
            'title' => (string) $item->title,
            'project' => $item->project?->name,
            'project_id' => (int) $item->project_id,
            'state' => $item->state?->name,
            'priority' => $item->priority === WorkItem::PRIORITY_NONE ? null : $item->priority,
            'assignee' => $item->assignees->first()?->full_name,
            'snippet' => $this->snippet($item->plainDescription(), $query),
            'updated_at' => optional($item->updated_at)->toIso8601String(),
            // Built here, not in the client: the client would have to know the work item URL
            // pattern, and §11.1 requires the drawer to open on arrival.
            'url' => route('projects.work-items', ['project' => $item->project_id]).'?item='.$item->id.'&tab=all',
        ];
    }

    /** A short window of description around the match, for context under the title (§8.2). */
    private function snippet(string $text, string $query): ?string
    {
        if ($text === '') {
            return null;
        }

        $at = mb_stripos($text, $query);
        $start = $at === false ? 0 : max(0, $at - 40);
        $window = mb_substr($text, $start, 160);

        return ($start > 0 ? '…' : '').$window.(mb_strlen($text) > $start + 160 ? '…' : '');
    }
}
