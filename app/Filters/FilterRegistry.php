<?php

namespace App\Filters;

use App\Filters\WorkItems\LabelFilter;
use App\Filters\WorkItems\MembersFilter;
use App\Filters\WorkItems\PriorityFilter;
use App\Filters\WorkItems\StatusFilter;
use App\Models\Project;

/**
 * Which categories a screen filters by (docs/features/filters.md).
 *
 * Epics, Estimation, Labels and saved Views each add an entry here when their screen next moves.
 * The engine does not change for any of them — that is the whole point of having written it once.
 */
class FilterRegistry
{
    /** @return array<int, FilterCategory> */
    public function workItems(Project $project): array
    {
        $categories = [
            new StatusFilter,
            new MembersFilter,
            new PriorityFilter,
        ];

        // Nothing to filter by while the feature is off, and a category with no values is a
        // dead end in the panel.
        if ($project->featureEnabled('labels')) {
            $categories[] = new LabelFilter;
        }

        return $categories;
    }

    /**
     * What the panel draws: every category, with its values.
     *
     * @param  array<int, FilterCategory>  $categories
     * @return array<int, array<string, mixed>>
     */
    public function describe(array $categories, Project $project): array
    {
        return collect($categories)
            ->map(fn (FilterCategory $c) => [
                'key' => $c->key(),
                'label' => $c->label(),
                'options' => $c->options($project),
            ])
            // A category with nothing in it is not offered — an empty list reads as broken.
            ->filter(fn (array $c) => $c['options'] !== [])
            ->values()
            ->all();
    }
}
