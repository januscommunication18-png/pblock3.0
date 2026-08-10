<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Workspace-wide project lifecycle state (spec §6). TENANT-SCOPED (BelongsToTenant).
 */
class ProjectState extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'color', 'description', 'group', 'is_default', 'position',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'position' => 'integer',
        ];
    }
}
