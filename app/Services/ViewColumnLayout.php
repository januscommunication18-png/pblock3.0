<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectView;
use App\Models\ProjectViewColumn;
use Illuminate\Support\Facades\DB;

/**
 * A View's column arrangement (Views §6.4, §8, §9.3).
 *
 * Three jobs: seed a new View with sensible columns, describe the saved columns to the client
 * with their current availability, and apply a drag — which is the one that has to be careful.
 */
class ViewColumnLayout
{
    public function __construct(private readonly ViewFieldCatalog $catalog) {}

    /**
     * The columns a new View starts with (§6.4).
     *
     * The feature columns are included only when the project has that feature on RIGHT NOW.
     * Seeding a cycle column into a project without cycles would produce a View whose first
     * impression is a greyed-out unavailable column, which is a poor introduction to a feature
     * whose whole point is showing you your data.
     *
     * @return array<int, ProjectViewColumn>
     */
    public function seedDefaults(ProjectView $view, Project $project): array
    {
        $fixed = ['work_item.identifier', 'work_item.title', 'work_item.state'];

        $scroll = ['work_item.priority', 'member.assignees'];

        // In §6.4's order, each one only if its feature is on.
        foreach (['epic.title', 'module.title', 'cycle.name', 'estimate.value'] as $key) {
            if ($this->catalog->isAvailable($project, $this->catalog->find($key))) {
                $scroll[] = $key;
            }
        }

        $scroll[] = 'work_item.start_date';
        $scroll[] = 'work_item.due_date';

        if ($this->catalog->isAvailable($project, $this->catalog->find('label.labels'))) {
            $scroll[] = 'label.labels';
        }

        $created = [];

        foreach ([ProjectViewColumn::POSITION_FIXED => $fixed, ProjectViewColumn::POSITION_SCROLL => $scroll] as $position => $keys) {
            foreach (array_values($keys) as $order => $key) {
                $created[] = $this->create($view, $key, $position, $order);
            }
        }

        return $created;
    }

    /**
     * Add one column to a View (§9).
     *
     * Appended to the end of its card, because the user chose the field and not a position;
     * they can drag it where they want it, and inserting it somewhere they did not ask for is
     * the more surprising of the two.
     */
    public function add(ProjectView $view, string $key, string $position): ProjectViewColumn
    {
        $order = (int) ProjectViewColumn::query()
            ->where('project_view_id', $view->id)
            ->where('position_type', $position)
            ->max('sort_order');

        return $this->create($view, $key, $position, $order + 1);
    }

    /**
     * Apply a whole arrangement at once (§8.3).
     *
     * The entire layout arrives in one request rather than a message per moved column, because
     * one drag between cards changes the moved column's `position_type` AND the `sort_order`
     * of everything around it in both cards. Sent piecemeal, a single failure would leave a
     * layout that is half-applied and has no correct interpretation — so it is one transaction
     * that either lands or does not.
     *
     * Ids not belonging to this View are ignored rather than rejected: the client sends what it
     * rendered, and a stale tab is not a reason to refuse a legitimate drag.
     *
     * @param  array<string, array<int, int>>  $layout  position type => column ids in order
     */
    public function reorder(ProjectView $view, array $layout): void
    {
        $owned = ProjectViewColumn::query()
            ->where('project_view_id', $view->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        DB::transaction(function () use ($view, $layout, $owned) {
            foreach ([ProjectViewColumn::POSITION_FIXED, ProjectViewColumn::POSITION_SCROLL] as $position) {
                $ids = array_values(array_intersect(
                    array_map('intval', $layout[$position] ?? []),
                    $owned,
                ));

                foreach ($ids as $order => $id) {
                    ProjectViewColumn::query()
                        ->where('project_view_id', $view->id)
                        ->where('id', $id)
                        ->update(['position_type' => $position, 'sort_order' => $order]);
                }
            }
        });
    }

    /**
     * The saved columns as the grid needs them, in render order.
     *
     * `available` is resolved here on every read (§9.3): a column whose feature was switched
     * off keeps its row, its position, its order and its width, and is simply reported as
     * unavailable — so re-enabling the feature restores it with nothing to migrate and no
     * write to have gone missing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describe(ProjectView $view, Project $project): array
    {
        return $view->columns->map(function (ProjectViewColumn $column) use ($project) {
            $field = $this->catalog->find($column->key());
            $available = $this->catalog->isColumnAvailable($project, $column);

            return [
                'id' => $column->id,
                'key' => $column->key(),
                'source' => $column->source_type,
                'field' => $column->source_field,
                // The alias when the user set one, else the catalog's label so a central
                // rename reaches every View that did not deliberately override it.
                'label' => $column->display_name ?: ($field['label'] ?? $column->source_field),
                'display_name' => $column->display_name,
                'type' => $field['type'] ?? 'text',
                'position' => $column->position_type,
                'sort_order' => $column->sort_order,
                'width' => $column->width ?: ($field['width'] ?? 160),
                'visible' => $column->is_visible,
                // What the CONFIGURATION says. Whether this user may write a given cell is
                // decided per request by the policy — never sent as a permission.
                'editable' => $this->catalog->isCellEditable($project, $column),
                'available' => $available,
                'reason' => $available ? null : $this->unavailable($field),
            ];
        })->values()->all();
    }

    /** §7.2: the grid's frozen-column count is derived from the enabled Fixed columns. */
    public function frozenCount(ProjectView $view): int
    {
        return $view->columns->filter(fn (ProjectViewColumn $c) => $c->isFixed() && $c->is_visible)->count();
    }

    private function create(ProjectView $view, string $key, string $position, int $order): ProjectViewColumn
    {
        [$source, $field] = explode('.', $key, 2);

        return ProjectViewColumn::create([
            'project_view_id' => $view->id,
            'source_type' => $source,
            'source_field' => $field,
            'position_type' => $position,
            'sort_order' => $order,
        ]);
    }

    /** @param  array<string, mixed>|null  $field */
    private function unavailable(?array $field): string
    {
        if ($field === null) {
            return 'This field is no longer available.';
        }

        $names = [
            'epics' => 'Epics', 'cycles' => 'Cycles', 'modules' => 'Modules',
            'estimates' => 'Estimation', 'labels' => 'Labels',
        ];

        return ($names[$field['feature']] ?? $field['feature']).' is turned off for this project.';
    }
}
