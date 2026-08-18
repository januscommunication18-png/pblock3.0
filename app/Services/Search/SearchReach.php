<?php

namespace App\Services\Search;

use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;

/**
 * What one user is allowed to find, expressed as project id sets (global-search.md §12).
 *
 * Search cannot ask a policy per row — the engine has to be told what to exclude BEFORE it
 * ranks anything, or it spends its budget on records that will be thrown away and the result
 * counts describe a world the user cannot see. So reach is resolved once per query, here, into
 * two sets the engine can filter on.
 *
 * The rules are not re-implemented: this asks the same policies the rest of the app asks
 * (`view` on the project, `canSeeEveryItem` for §10's assigned-only setting). A second copy of
 * an access rule is a second place for it to drift, and search is the worst place to be wrong.
 */
class SearchReach
{
    /** @var array<int>|null Projects the user can view AND see every item in. */
    private ?array $open = null;

    /** @var array<int>|null Projects they can view, but only their own assigned items. */
    private ?array $restricted = null;

    /** @var array<int>|null Wiki collections they may open. */
    private ?array $wikiCollections = null;

    public function __construct(private readonly User $user, private readonly ?string $tenantId) {}

    /** @return array<int> */
    public function openProjectIds(): array
    {
        $this->resolve();

        return $this->open;
    }

    /** @return array<int> */
    public function restrictedProjectIds(): array
    {
        $this->resolve();

        return $this->restricted;
    }

    /** Every project the user may search in at all. @return array<int> */
    public function projectIds(): array
    {
        return array_merge($this->openProjectIds(), $this->restrictedProjectIds());
    }

    /** Nothing to search — the caller can skip the engine entirely. */
    public function isEmpty(): bool
    {
        return $this->projectIds() === [];
    }

    /**
     * Wiki collections this person may open.
     *
     * Delegates to `WikiCollection::visibleTo()` — the same scope every wiki listing already
     * uses, and whose docblock spells out why: a collection somebody cannot open must not be
     * NAMED to them either, because a private row that appears and then refuses is a row that
     * has already announced it exists. A search box is the most efficient way there is to
     * enumerate such names, so it uses that scope rather than a rule of its own.
     *
     * @return array<int>
     */
    public function wikiCollectionIds(): array
    {
        if ($this->wikiCollections !== null) {
            return $this->wikiCollections;
        }

        if ($this->tenantId === null) {
            return $this->wikiCollections = [];
        }

        return $this->wikiCollections = \App\Models\WikiCollection::query()
            ->visibleTo($this->user)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function resolve(): void
    {
        if ($this->open !== null) {
            return;
        }

        $this->open = [];
        $this->restricted = [];

        if ($this->tenantId === null) {
            return;
        }

        $policy = app(\App\Policies\WorkItemPolicy::class);

        // Archived projects are excluded: their work items are not reachable by navigation
        // either, and a search result that leads somewhere the user cannot open is worse than
        // no result at all.
        Project::query()
            ->where('tenant_id', $this->tenantId)
            ->whereNull('archived_at')
            ->get()
            ->each(function (Project $project) use ($policy) {
                if (! $this->user->can('view', $project)) {
                    return;
                }

                if ($policy->canSeeEveryItem($this->user, $project)) {
                    $this->open[] = (int) $project->id;
                } else {
                    $this->restricted[] = (int) $project->id;
                }
            });
    }

    /**
     * Narrow a work item query to what this user may actually open.
     *
     * Applied during hydration rather than as an engine filter because the assigned-only rule
     * turns on a pivot table, which Scout's `database` driver cannot filter on — see decision
     * G6. It can only ever REMOVE rows, so it cannot leak; the caller over-fetches so the
     * visible group still fills up.
     */
    public function constrain(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $open = $this->openProjectIds();
        $restricted = $this->restrictedProjectIds();
        $userId = $this->user->id;

        return $query->where(function ($outer) use ($open, $restricted, $userId) {
            $outer->whereIn('project_id', $open ?: [0]);

            if ($restricted !== []) {
                $outer->orWhere(function ($inner) use ($restricted, $userId) {
                    $inner->whereIn('project_id', $restricted)
                        ->whereHas('assignees', fn ($a) => $a->whereKey($userId));
                });
            }
        })->whereNull('archived_at');
    }

    /** The model class this reach describes, for callers that want to be explicit. */
    public function subject(): string
    {
        return WorkItem::class;
    }
}
