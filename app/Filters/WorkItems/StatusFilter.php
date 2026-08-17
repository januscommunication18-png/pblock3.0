<?php

namespace App\Filters\WorkItems;

use App\Filters\FilterCategory;
use App\Models\Project;
use App\Models\ProjectItemState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** The project's configured states — Backlog, To Do, In Progress, Done, Cancelled. */
class StatusFilter implements FilterCategory
{
    public function key(): string
    {
        return 'status';
    }

    public function label(): string
    {
        return 'Status';
    }

    /** @return array<int, array<string, mixed>> */
    public function options(Project $project): array
    {
        return $this->states($project)
            ->map(fn (ProjectItemState $s) => [
                'value' => (string) $s->id,
                'label' => $s->name,
                'color' => $s->color,
            ])
            ->all();
    }

    public function apply(Builder $query, array $values): void
    {
        // Grouped, always. See FilterCategory::apply().
        $query->where(fn (Builder $q) => $q->whereIn('state_id', $values));
    }

    public function valid(array $values, Project $project): array
    {
        // F-8 — a state id from another project is dropped. A filter is not a way to ask
        // whether something exists elsewhere.
        $own = $this->states($project)->map(fn (ProjectItemState $s) => (string) $s->id)->all();

        return array_values(array_intersect($values, $own));
    }

    public function chip(array $values, Project $project): string
    {
        return $this->states($project)
            ->whereIn('id', array_map('intval', $values))
            ->pluck('name')
            ->implode(', ');
    }

    /** @return Collection<int, ProjectItemState> */
    private function states(Project $project)
    {
        return ProjectItemState::query()
            ->where('project_id', $project->id)
            ->orderBy('position')
            ->get();
    }
}
