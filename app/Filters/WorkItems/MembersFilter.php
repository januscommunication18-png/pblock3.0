<?php

namespace App\Filters\WorkItems;

use App\Filters\FilterCategory;
use App\Models\Project;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Assignees — and Unassigned, in the same category.
 *
 * "Sarah or unassigned" is a real question somebody asks while triaging. Putting Unassigned in a
 * category of its own would make it an AND, and "assigned to Sarah AND assigned to nobody"
 * answers nothing (F-D4).
 */
class MembersFilter implements FilterCategory
{
    public const UNASSIGNED = 'unassigned';

    public function key(): string
    {
        return 'members';
    }

    public function label(): string
    {
        return 'Members';
    }

    /** @return array<int, array<string, mixed>> */
    public function options(Project $project): array
    {
        $options = $this->members($project)
            ->map(fn (WorkspaceMembership $m) => [
                'value' => (string) $m->user_id,
                'label' => $m->user?->displayName(),
                'initial' => $m->user?->initial(),
                'avatar' => $m->user?->avatar_url,
            ])
            ->all();

        array_unshift($options, ['value' => self::UNASSIGNED, 'label' => 'Unassigned']);

        return $options;
    }

    public function apply(Builder $query, array $values): void
    {
        $ids = array_values(array_diff($values, [self::UNASSIGNED]));
        $wantsNobody = in_array(self::UNASSIGNED, $values, true);

        $query->where(function (Builder $q) use ($ids, $wantsNobody) {
            if ($ids !== []) {
                $q->whereHas('assignees', fn (Builder $a) => $a->whereIn('users.id', $ids));
            }

            if ($wantsNobody) {
                $ids === []
                    ? $q->whereDoesntHave('assignees')
                    : $q->orWhereDoesntHave('assignees');
            }
        });
    }

    public function valid(array $values, Project $project): array
    {
        $own = $this->members($project)->map(fn (WorkspaceMembership $m) => (string) $m->user_id)->all();
        $own[] = self::UNASSIGNED;

        return array_values(array_intersect($values, $own));
    }

    public function chip(array $values, Project $project): string
    {
        $names = $this->members($project)
            ->mapWithKeys(fn (WorkspaceMembership $m) => [(string) $m->user_id => $m->user?->displayName()]);

        return collect($values)
            ->map(fn (string $v) => $v === self::UNASSIGNED ? 'Unassigned' : ($names[$v] ?? $v))
            ->implode(', ');
    }

    /** @return Collection<int, WorkspaceMembership> */
    private function members(Project $project)
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $project->tenant_id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->with('user')
            ->get()
            ->filter(fn (WorkspaceMembership $m) => $m->user !== null)
            ->values();
    }
}
