<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/** Project-scoped work-item state (Phase 4 / PRJ-043). TENANT-SCOPED (BelongsToTenant). */
class ProjectItemState extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'project_id', 'name', 'color', 'description', 'group', 'is_default', 'position',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'position' => 'integer'];
    }
}
