<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/** Workspace-scoped project label (spec §6). TENANT-SCOPED (BelongsToTenant). */
class ProjectLabel extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'color', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
