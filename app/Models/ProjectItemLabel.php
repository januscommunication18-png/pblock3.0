<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A project-scoped label (Project-Level Labels §20/§27). TENANT-SCOPED (BelongsToTenant).
 *
 * §20 keeps labels inside their project: one created in Project A never appears in Project B.
 * Workspace-wide labels are §21's future phase and deliberately not modelled here — adding a
 * nullable `project_id` "just in case" would make every query ask a question with no answer.
 */
class ProjectItemLabel extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'project_id', 'name', 'description', 'color', 'position', 'archived_at'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** The work items carrying this label (§19: a label goes on many, an item has many). */
    public function workItems(): BelongsToMany
    {
        return $this->belongsToMany(WorkItem::class, 'work_item_labels', 'label_id', 'work_item_id');
    }

    /** §11: an archived label leaves the picker but stays on the items already using it. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
