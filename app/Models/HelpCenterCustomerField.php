<?php

namespace App\Models;

use App\Models\Concerns\IsHelpCenterCustomField;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One custom field on a Space's Customer records (docs/features/help-center.md, P75 §2).
 * TENANT-SCOPED.
 *
 * The mirror of `HelpCenterCompanyField`, and everything it does lives in the trait they share.
 * Two tables and two models rather than one with a discriminator (HC-D53): the Company side
 * already existed with live rows, and the two lists are never read together.
 */
class HelpCenterCustomerField extends Model
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
        return HelpCenterCustomFieldValue::KIND_CUSTOMER;
    }
}
