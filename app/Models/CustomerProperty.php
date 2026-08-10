<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/** User-defined customer custom property (spec §11). TENANT-SCOPED (BelongsToTenant). */
class CustomerProperty extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'title', 'description', 'type', 'mandatory', 'active', 'options', 'position',
    ];

    protected function casts(): array
    {
        return [
            'mandatory' => 'boolean',
            'active' => 'boolean',
            'options' => 'array',
            'position' => 'integer',
        ];
    }
}
