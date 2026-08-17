<?php

namespace App\Filters\WorkItems;

use App\Filters\FilterCategory;
use App\Models\Project;
use App\Models\ProjectItemLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** The project's labels. Absent entirely where the project has Labels switched off. */
class LabelFilter implements FilterCategory
{
    public function key(): string
    {
        return 'label';
    }

    public function label(): string
    {
        return 'Label';
    }

    /** @return array<int, array<string, mixed>> */
    public function options(Project $project): array
    {
        return $this->labels($project)
            ->map(fn (ProjectItemLabel $l) => [
                'value' => (string) $l->id,
                'label' => $l->name,
                'color' => $l->color,
            ])
            ->all();
    }

    public function apply(Builder $query, array $values): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereHas('labels', fn (Builder $l) => $l->whereIn('project_item_labels.id', $values)));
    }

    public function valid(array $values, Project $project): array
    {
        $own = $this->labels($project)->map(fn (ProjectItemLabel $l) => (string) $l->id)->all();

        return array_values(array_intersect($values, $own));
    }

    public function chip(array $values, Project $project): string
    {
        return $this->labels($project)
            ->whereIn('id', array_map('intval', $values))
            ->pluck('name')
            ->implode(', ');
    }

    /** @return Collection<int, ProjectItemLabel> */
    private function labels(Project $project)
    {
        // An archived label leaves the picker and stays on the items using it — the same rule
        // the item's own label chooser follows.
        return ProjectItemLabel::query()
            ->where('project_id', $project->id)
            ->active()
            ->orderBy('name')
            ->get();
    }
}
