<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/** Colored release label (spec §8). TENANT-SCOPED (BelongsToTenant). */
class ReleaseLabel extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'color', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
