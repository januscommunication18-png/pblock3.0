<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/** Workspace-level wiki label (spec §7). TENANT-SCOPED (BelongsToTenant). */
class WikiLabel extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'color', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
