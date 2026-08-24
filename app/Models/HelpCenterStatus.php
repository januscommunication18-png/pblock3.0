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

    /**
     * Whose clock runs while a Request sits in this status (P9, Waiting Responsibility).
     *
     * Distinct from `responsibility` above, which decides who gets ASSIGNED. These decide who
     * OWES the next action, which is the only thing that makes a waiting period mean anything:
     * without it the Inbox can report a ticket's age and nothing else.
     */
    public const WAITING_AGENT = 'agent';

    public const WAITING_CUSTOMER = 'customer';

    public const WAITING_NEITHER = 'neither';

    public const RESPONSIBILITY_ASSIGNEE = 'assignee';

    /**
     * What a Request in this status does to its SLA clocks
     * (docs/features/helpdesk-sla.md, §19).
     *
     * Independent of `system_category`, which only seeded it (SLA-D8): a team may want an Active
     * status that pauses — "With Engineering", where nothing is owed to the customer.
     */
    public const SLA_CONTINUE = 'continue';

    public const SLA_PAUSE = 'pause';

    public const SLA_COMPLETE_RESOLUTION = 'complete_resolution';

    public const SLA_STOP = 'stop';

    /** Where the two system rows sit. Custom statuses are numbered between them. */
    public const POSITION_OPEN = 0;

    public const POSITION_CLOSED = 9999;

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'name',
        'color',
        'responsibility',
        'waiting_on',
        'is_default',
        'is_active',
        'system_key',
        'system_category',
        'sla_behavior',
        'position',
        'default_assignees',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
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

    /**
     * The status a new Request opens in, for one Space (P9, Default Status).
     *
     * Falls back to Open and then to the first status in workflow order, because a Space with no
     * default must still be able to receive email — an inbound message that cannot be given a
     * status would otherwise be dropped, and losing a customer's mail to a configuration gap is
     * far worse than opening it in the wrong column.
     */
    public static function defaultFor(int $spaceId): ?self
    {
        $statuses = static::query()->where('help_center_space_id', $spaceId)->ordered()->get();

        return $statuses->firstWhere('is_default', true)
            ?? $statuses->firstWhere('system_key', self::SYSTEM_OPEN)
            ?? $statuses->first();
    }

    /**
     * Make this the Space's starting status, and the only one.
     *
     * Cleared across the whole Space first: "default" is a property of the WORKFLOW, not of a
     * row, so two rows claiming it is not a state the product has an answer for.
     */
    public function makeDefault(): void
    {
        static::query()
            ->where('help_center_space_id', $this->help_center_space_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->forceFill(['is_default' => true])->save();
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
                // New mail lands here and is waiting on an agent from the moment it arrives.
                'waiting_on' => self::WAITING_AGENT,
                'is_default' => true,
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
                // The end of the workflow: nobody owes anything, so no clock runs.
                'waiting_on' => self::WAITING_NEITHER,
                'is_default' => false,
                // Always inactive, and not negotiable (P2 §16).
                'is_active' => false,
                'system_key' => self::SYSTEM_CLOSED,
                'position' => self::POSITION_CLOSED,
                'default_assignees' => [],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function waitingOptions(): array
    {
        return [self::WAITING_AGENT, self::WAITING_CUSTOMER, self::WAITING_NEITHER];
    }

    /** @return array<int, string> */
    public static function responsibilities(): array
    {
        return [self::RESPONSIBILITY_CREATOR, self::RESPONSIBILITY_ASSIGNEE];
    }

    /**
     * The System Categories a status may belong to (P54).
     *
     * From config, which is where the vocabulary lives (P53) — this model does not carry a second
     * copy of the five, so adding one is a config change and nothing else.
     */
    public static function systemCategories(): array
    {
        return array_keys((array) config('help-center.system_categories'));
    }

    public static function isSystemCategory(?string $key): bool
    {
        return $key !== null && in_array($key, self::systemCategories(), true);
    }

    /** The category's display label — "Active", not `active`. */
    public function systemCategoryLabel(): string
    {
        return (string) (config('help-center.system_categories.'.$this->system_category.'.label')
            ?? $this->system_category);
    }

    /** The vocabulary a status's SLA behavior may be set to (§19). */
    public static function slaBehaviors(): array
    {
        return array_keys((array) config('help-center.sla_behaviors'));
    }

    public static function isSlaBehavior(?string $key): bool
    {
        return $key !== null && in_array($key, self::slaBehaviors(), true);
    }

    public function slaBehaviorLabel(): string
    {
        return (string) (config('help-center.sla_behaviors.'.$this->sla_behavior.'.label')
            ?? $this->sla_behavior);
    }

    /**
     * Does time count while a Request sits here?
     *
     * `continue` is the only behavior that lets the clock move. The other three all stop it —
     * they differ in what they leave behind, which is the engine's business, not the caller's.
     */
    public function slaRuns(): bool
    {
        return $this->sla_behavior === self::SLA_CONTINUE;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'responsibility' => $this->responsibility,
            'waiting_on' => $this->waiting_on,
            'is_default' => (bool) $this->is_default,
            'is_active' => $this->is_active,
            'system_key' => $this->system_key,
            'system_category' => $this->system_category,
            'sla_behavior' => $this->sla_behavior,
            'sla_behavior_label' => $this->slaBehaviorLabel(),
            'editable' => $this->isEditable(),
            'position' => $this->position,
            'default_assignees' => array_values((array) $this->default_assignees),
        ];
    }
}
