<?php

namespace App\Models\Concerns;

/**
 * The tenant id to stamp on a many-to-many pivot row.
 *
 * Every pivot table in this app carries `tenant_id`, and the relations pass it to
 * `withPivotValue()` so a sync() cannot insert a row without one. The obvious
 * `$this->tenant_id` is not enough on its own:
 *
 * **Eager loading builds the relation on a BLANK instance.** `with('members')` asks a fresh,
 * attribute-less model for its relation in order to construct the query, so `$this->tenant_id`
 * is null at that moment and `withPivotValue()` throws "The provided value may not be null" —
 * a 500 on any page that eager-loads one of these.
 *
 * Falling back to the active tenant is correct rather than merely convenient: these queries
 * only ever run inside a tenancy context (the workspace.tenancy middleware), and in
 * single-database tenancy the pivot belongs to the workspace being browsed either way.
 */
trait StampsPivotTenant
{
    protected function pivotTenantId(): string
    {
        return (string) ($this->tenant_id ?? tenant()?->getTenantKey() ?? '');
    }
}
