<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * The no-overlapping-cycles rule (Cycles §3.3.2 / §6.2 / §11).
 *
 * With Parallel Cycles OFF a project may only ever have one cycle running at a time, so a new
 * or edited date range may not overlap another Active or Upcoming cycle. Completed cycles are
 * excluded: a sprint that already finished is not a scheduling conflict.
 *
 * Checked at WRITE time only. §3.3.4 is explicit that turning Parallel Cycles off must not
 * mutate anything — existing overlaps survive untouched, they simply cannot be added to.
 *
 * Lives here rather than inline in a form request so the store and update paths cannot drift
 * apart, which is the usual way a rule like this ends up half-enforced.
 */
class CycleOverlapGuard
{
    /**
     * Is this range free of conflicts?
     *
     * @param  int|null  $ignoreCycleId  The cycle being edited — it cannot conflict with itself.
     */
    public function allows(Project $project, string $startDate, string $endDate, ?int $ignoreCycleId = null): bool
    {
        return $this->conflicting($project, $startDate, $endDate, $ignoreCycleId) === null;
    }

    /** The first cycle this range would collide with, or null when the range is free. */
    public function conflicting(Project $project, string $startDate, string $endDate, ?int $ignoreCycleId = null): ?Cycle
    {
        // §3.3.3: with the feature on, overlapping is the entire point.
        if ($project->featureEnabled('parallel_cycles') && $project->entitledTo('parallel_cycles')) {
            return null;
        }

        return Cycle::query()
            ->forProject($project->id)
            ->when($ignoreCycleId, fn ($q) => $q->whereKeyNot($ignoreCycleId))
            // Not yet finished — Active or Upcoming (§3.3.2).
            ->assignable()
            // Two ranges overlap unless one ends before the other starts. Inclusive on both
            // ends, matching §9's inclusive definition of Active.
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate)
            ->orderBy('start_date')
            ->first();
    }

    /** The message shown when a range is refused, naming what it collides with. */
    public function message(Cycle $conflict): string
    {
        return sprintf(
            'These dates overlap “%s” (%s – %s). Turn on Parallel cycles in project settings to run cycles at the same time.',
            $conflict->name,
            $conflict->start_date?->format('M j, Y'),
            $conflict->end_date?->format('M j, Y'),
        );
    }

    /** Today, as the date string the queries above compare against. */
    public function today(): string
    {
        return Carbon::today()->toDateString();
    }
}
