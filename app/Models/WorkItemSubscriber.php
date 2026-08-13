<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One person following one work item (POC toolbar).
 *
 * TENANT-SCOPED (CLAUDE.md §7). Separate from `project_subscribers` on purpose: that answers
 * "tell me about this project", this answers "tell me about this work item", and somebody
 * following one contentious item does not thereby want everything else in the project.
 */
class WorkItemSubscriber extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'work_item_id', 'user_id'];

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
