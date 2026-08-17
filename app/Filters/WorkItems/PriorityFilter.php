<?php

namespace App\Filters\WorkItems;

use App\Filters\FilterCategory;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;

/**
 * Urgent, High, Medium, Low, None.
 *
 * "No priority" needs no special handling here: `none` is a real value in
 * `config('projects.work_item_priorities')`, and `work_items.priority` is NOT NULL with `none`
 * as its default. The absence of a priority is spelled out in this schema rather than left as a
 * null, so the filter is a plain `whereIn` and the "or it is unset" branch some other categories
 * need does not exist.
 */
class PriorityFilter implements FilterCategory
{
    public function key(): string
    {
        return 'priority';
    }

    public function label(): string
    {
        return 'Priority';
    }

    /** @return array<int, array<string, mixed>> */
    public function options(Project $project): array
    {
        return collect(config('projects.work_item_priorities'))
            ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])
            ->values()
            ->all();
    }

    public function apply(Builder $query, array $values): void
    {
        // Grouped, always. See FilterCategory::apply().
        $query->where(fn (Builder $q) => $q->whereIn('priority', $values));
    }

    public function valid(array $values, Project $project): array
    {
        $known = array_keys((array) config('projects.work_item_priorities'));

        return array_values(array_intersect($values, $known));
    }

    public function chip(array $values, Project $project): string
    {
        $labels = (array) config('projects.work_item_priorities');

        return collect($values)->map(fn (string $v) => $labels[$v] ?? $v)->implode(', ');
    }
}
