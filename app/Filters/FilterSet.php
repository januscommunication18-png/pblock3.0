<?php

namespace App\Filters;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The filters a request is asking for, validated and ready to apply
 * (docs/features/filters.md).
 *
 * AND between categories, OR within one — stated here, once, so no screen has to restate it and
 * no two screens can disagree about it.
 */
class FilterSet
{
    /** @param  array<string, array<int, string>>  $values  category key => surviving values */
    private function __construct(
        private readonly array $values,
        /** @var array<string, FilterCategory> */
        private readonly array $categories,
        private readonly Project $project,
    ) {}

    /**
     * Read the filters out of the address.
     *
     * `?status=in_progress,todo&priority=high` — comma-separated, because the address is the
     * state (F-D2) and a link somebody pastes into chat should be readable.
     *
     * @param  array<int, FilterCategory>  $categories
     */
    public static function fromRequest(Request $request, array $categories, Project $project): self
    {
        $byKey = [];
        $values = [];

        foreach ($categories as $category) {
            $byKey[$category->key()] = $category;

            $raw = $request->query($category->key());

            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            $asked = collect(explode(',', $raw))
                ->map(fn (string $v) => trim($v))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $surviving = $category->valid($asked, $project);

            // F-5 — a category with nothing valid in it is not a filter. `?status=` is the
            // unfiltered list, not "items whose status is nothing".
            if ($surviving !== []) {
                $values[$category->key()] = $surviving;
            }
        }

        return new self($values, $byKey, $project);
    }

    /** An unfiltered set — for callers with no request in hand. */
    public static function none(Project $project): self
    {
        return new self([], [], $project);
    }

    /**
     * Narrow the query.
     *
     * Each category is applied once, in its own nested group, so the ORs inside one cannot reach
     * the others. Applied BEFORE any limit by every caller — filtering a page rather than a query
     * returns the first N rows and then discards some, which reads as a filter losing things
     * (F-3).
     */
    public function apply(Builder $query): Builder
    {
        foreach ($this->values as $key => $values) {
            $this->categories[$key]->apply($query, $values);
        }

        return $query;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /** @return array<string, array<int, string>> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * The chips above the list — one per CATEGORY, naming the values inside it.
     *
     * The category is the unit of the AND, so it is the unit of removal (F-D5).
     *
     * @return array<int, array<string, mixed>>
     */
    public function chips(): array
    {
        $chips = [];

        foreach ($this->values as $key => $values) {
            $chips[] = [
                'key' => $key,
                'label' => $this->categories[$key]->label(),
                'text' => $this->categories[$key]->chip($values, $this->project),
                'count' => count($values),
            ];
        }

        return $chips;
    }
}
