<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One state movement on a work item (spec §10). TENANT-SCOPED and immutable in the product:
 * nothing in the UI edits, deletes or backdates these (§11.9 applies equally here).
 */
class WorkItemTransition extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'project_id', 'work_item_id',
        'from_state_id', 'to_state_id', 'from_state_name', 'to_state_name',
        'actor_id', 'transitioned_at',
    ];

    protected function casts(): array
    {
        return ['transitioned_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
