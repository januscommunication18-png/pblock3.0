<?php

namespace App\Filters;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;

/**
 * One filter category — Status, Priority, Members … (docs/features/filters.md).
 *
 * Deliberately dataset-agnostic. Work Items is the first screen to use these; Epics, Estimation,
 * Labels and saved Views were each waiting for the same infrastructure (views.md V3), and they
 * get it by contributing their own categories to the registry rather than by growing a second
 * filter implementation.
 */
interface FilterCategory
{
    /** The key in the URL: `?status=…`. */
    public function key(): string;

    /** What the panel calls it. */
    public function label(): string;

    /**
     * The values this category offers, for the panel.
     *
     * @return array<int, array<string, mixed>> `['value' => string, 'label' => string, …]`
     */
    public function options(Project $project): array;

    /**
     * Narrow the query to these values, OR'd together.
     *
     * IMPLEMENTATIONS MUST WRAP THEIR CONDITIONS IN A NESTED GROUP — `$query->where(fn ($q) => …)`.
     * A bare `orWhere` here leaks across everything the caller has already applied, which widens
     * the result instead of narrowing it. That is the one mistake this interface exists to make
     * hard to hide, and `FilterSet` cannot prevent it from the outside.
     *
     * @param  array<int, string>  $values  already validated by `valid()`
     */
    public function apply(Builder $query, array $values): void;

    /**
     * The values of this category that actually mean something for this project.
     *
     * Anything else is dropped rather than refused: a filter is a view of a list, and a stale
     * bookmark should show a slightly different list rather than an error page.
     *
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    public function valid(array $values, Project $project): array;

    /**
     * What the chip above the list reads, for the values that survived.
     *
     * @param  array<int, string>  $values
     */
    public function chip(array $values, Project $project): string;
}
