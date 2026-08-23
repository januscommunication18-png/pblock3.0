<?php

namespace App\Models;

use App\Models\Concerns\IsHelpCenterCustomField;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One custom field on a Space's Company records (docs/features/help-center.md, P18).
 * TENANT-SCOPED.
 *
 * The behaviour moved to `IsHelpCenterCustomField` when P75 added the Customer half: the two
 * are the same model over two tables, and the answer to "does a Radio need choices?" has to be
 * one answer or the two forms validate differently. What stays here is what is specific to this
 * table — its columns and its casts.
 */
class HelpCenterCompanyField extends Model
{
    use BelongsToTenant;
    use IsHelpCenterCustomField;

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'name',
        'type',
        'is_required',
        'is_active',
        'options',
        'position',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'options' => 'array',
            'position' => 'integer',
        ];
    }

    public static function fieldKind(): string
    {
        return HelpCenterCustomFieldValue::KIND_COMPANY;
    }
}
