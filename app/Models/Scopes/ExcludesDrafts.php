<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Keeps drafts out of every work item query (Drafts §3, decision D-D2).
 *
 * A draft has no project, no state and no ID number, so it belongs in none of the places a
 * work item is listed, counted, searched or picked from. Applied globally rather than by each
 * caller for the same reason tenancy is (CLAUDE.md §7): isolation that depends on a developer
 * remembering a `where` is isolation that eventually leaks. Every existing query was correct
 * before drafts existed and stays correct without being touched.
 *
 * `WorkItem::drafts()` is the one opt-out, and the Drafts screen is its only caller.
 */
class ExcludesDrafts implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('is_draft'), false);
    }
}
