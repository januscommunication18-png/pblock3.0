<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One status in a Space's conversation workflow
 * (docs/features/help-center.md, P2 §11–§16). TENANT-SCOPED.
 *
 * Every Space has at least two: **Open** first and **Closed** last, both protected. Whatever a
 * team adds sits strictly between them — `Open → custom… → Closed` (P2 §16).
 *
 * What makes a row protected is `system_key`, not its name or its position (HC-D14). A user may
 * type "Open" as a custom status name and rows can be dragged anywhere; neither creates nor
 * destroys a system status.
 */
class HelpCenterStatus extends Model
{
    use BelongsToTenant;

    public const SYSTEM_OPEN = 'open';

    public const SYSTEM_CLOSED = 'closed';

    public const RESPONSIBILITY_CREATOR = 'creator';

    public const RESPONSIBILITY_ASSIGNEE = 'assignee';

    /** Where the two system rows sit. Custom statuses are numbered between them. */
    public const POSITION_OPEN = 0;

    public const POSITION_CLOSED = 9999;

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'name',
        'color',
        'responsibility',
        'is_active',
        'system_key',
        'position',
        'default_assignees',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
            'default_assignees' => 'array',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    /** In workflow order: Open, then the custom statuses, then Closed. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function isSystem(): bool
    {
        return $this->system_key !== null;
    }

    public function isOpen(): bool
    {
        return $this->system_key === self::SYSTEM_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->system_key === self::SYSTEM_CLOSED;
    }

    /** P2 §16: only custom statuses can be deleted, renamed or moved. */
    public function isEditable(): bool
    {
        return ! $this->isSystem();
    }

    /**
     * The two statuses every Space is created with (P2 §13, §15).
     *
     * Defined here rather than in the service that writes them, because "a Space always has an
     * Open and a Closed" is a fact about the workflow, and the wizard's Step 4 needs to show
     * them before any of it is saved (HC-D11) — the draft and the commit have to agree about
     * what the defaults are, so there is one statement of them.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function systemDefaults(): array
    {
        return [
            [
                'name' => 'Open',
                'color' => '#22c55e',
                'responsibility' => self::RESPONSIBILITY_ASSIGNEE,
                // Always active, and not negotiable (P2 §16).
                'is_active' => true,
                'system_key' => self::SYSTEM_OPEN,
                'position' => self::POSITION_OPEN,
                'default_assignees' => [],
            ],
            [
                'name' => 'Closed',
                'color' => '#6b7280',
                'responsibility' => self::RESPONSIBILITY_ASSIGNEE,
                // Always inactive, and not negotiable (P2 §16).
                'is_active' => false,
                'system_key' => self::SYSTEM_CLOSED,
                'position' => self::POSITION_CLOSED,
                'default_assignees' => [],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function responsibilities(): array
    {
        return [self::RESPONSIBILITY_CREATOR, self::RESPONSIBILITY_ASSIGNEE];
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'responsibility' => $this->responsibility,
            'is_active' => $this->is_active,
            'system_key' => $this->system_key,
            'editable' => $this->isEditable(),
            'position' => $this->position,
            'default_assignees' => array_values((array) $this->default_assignees),
        ];
    }
}
