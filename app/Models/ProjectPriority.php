<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/** Workspace-scoped project priority (Urgent/High/Medium/Low/None). TENANT-SCOPED. */
class ProjectPriority extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'color', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
