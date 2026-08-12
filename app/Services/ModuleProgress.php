<?php

namespace App\Services;

use App\Models\Module;
use Illuminate\Support\Collection;

/**
 * Module progress, from the work items linked to it (Module Management §10).
 *
 * `Progress % = completed / total × 100`, where "completed" means the work item's state sits
 * in the project's **completed group** — never a state named "Done". §10.2 is explicit about
 * that: states are user-configurable, so matching on a name would break the moment somebody
 * renamed one.
 *
 * Counted for a whole list in one grouped query rather than per module, so a project with
 * thirty modules costs one round trip and not thirty (§20 Performance).
 */
class ModuleProgress
{
    /** The state groups reported in the breakdown (§10.3), in reading order. */
    private const GROUPS = ['completed', 'started', 'unstarted', 'backlog', 'cancelled'];

    /**
     * @param  Collection<int, Module>|array<int, Module>  $modules
     * @return array<int, array<string, int>> keyed by module id
     */
    public function forModules(iterable $modules): array
    {
        $ids = collect($modules)->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $rows = \DB::table('module_work_items')
            ->join('work_items', 'work_items.id', '=', 'module_work_items.work_item_id')
            ->leftJoin('project_item_states', 'project_item_states.id', '=', 'work_items.state_id')
            ->whereIn('module_work_items.module_id', $ids)
            // An archived work item is not part of the current body of work.
            ->whereNull('work_items.archived_at')
            ->select('module_work_items.module_id', 'project_item_states.group as state_group')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('module_work_items.module_id', 'project_item_states.group')
            ->get();

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->shape($rows->where('module_id', $id));
        }

        return $out;
    }

    public function forModule(Module $module): array
    {
        return $this->forModules([$module])[$module->id] ?? $this->shape(collect());
    }

    /** @return array<string, int> */
    private function shape(Collection $rows): array
    {
        $counts = [];
        foreach (self::GROUPS as $group) {
            $counts[$group] = (int) $rows->where('state_group', $group)->sum('total');
        }

        // A work item with no state at all still counts towards the total, so `total` is the
        // sum of the rows rather than the sum of the buckets.
        $total = (int) $rows->sum('total');
        $counts['total'] = $total;
        // §10.1: zero work items is 0%, not a division by zero.
        $counts['percent'] = $total > 0 ? (int) round($counts['completed'] / $total * 100) : 0;

        return $counts;
    }
}
