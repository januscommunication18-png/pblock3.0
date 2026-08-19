<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A separate support operation inside one Help Desk (Workspace & Inbox Assignment requirements
 * §3, §14). TENANT-SCOPED.
 *
 * What the requirements call a "Help Center Workspace": a brand, a business unit, a region or a
 * team with its own inboxes and its own conversations, run independently of the others under the
 * same ProjectBlock account. Named a SPACE here because `Workspace` is already the tenant
 * (decision H37).
 *
 * A space owns inboxes and nothing else directly. Conversations, messages and email addresses
 * all hang off an inbox, so "this space's conversations" is one hop away and stays true when an
 * inbox moves — which is exactly what §7 promises when it says a move keeps everything attached
 * to the inbox.
 */
class HelpDeskSpace extends Model
{
    use BelongsToTenant;

    /**
     * §5's optional categorization — now a list the reader writes, not a list they pick from.
     *
     * These six are OFFERED, not enforced: they are the examples the requirements give, kept as
     * one-press suggestions so the common cases stay consistent, while anything else can simply
     * be typed. A space is often more than one of them at once, which is why it holds several.
     *
     * Still labels rather than modes: nothing branches on a value. The moment something does, it
     * stops being a category and needs a decision — and a table — of its own (H45).
     */
    public const TYPE_SUGGESTIONS = [
        'Customer Support',
        'Partner Support',
        'Retail Support',
        'Internal Support',
        'Business Unit',
        'Other',
    ];

    /** As many as one space can carry, so a chip list stays a list rather than a paragraph. */
    public const MAX_TYPES = 8;

    /** Long enough for "Enterprise Customer Success", short enough to render as a chip. */
    public const MAX_TYPE_LENGTH = 40;

    protected $fillable = [
        'tenant_id',
        'help_desk_id',
        'name',
        'description',
        'types',
        'color',
        'created_by',
    ];

    /** The four steps of the Setup Inbox flow, in order. */
    public const STEP_NAME = 1;

    public const STEP_ADDRESSES = 2;

    public const STEP_CONNECT = 3;

    public const STEP_TEAM = 4;

    public const LAST_STEP = self::STEP_TEAM;

    protected function casts(): array
    {
        return [
            'types' => 'array',
            'setup_step' => 'integer',
            'setup_completed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function helpDesk(): BelongsTo
    {
        return $this->belongsTo(HelpDesk::class);
    }

    /** The inboxes assigned to this space (§10). One space, many inboxes; one inbox, one space. */
    public function inboxes(): HasMany
    {
        return $this->hasMany(HelpDeskInbox::class, 'help_desk_space_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** The inbox the Setup Inbox flow is configuring, if it has got that far. */
    public function setupInbox(): BelongsTo
    {
        return $this->belongsTo(HelpDeskInbox::class, 'setup_inbox_id');
    }

    /** Has this space finished the Setup Inbox flow? */
    public function isSetUp(): bool
    {
        return $this->setup_completed_at !== null;
    }

    /**
     * The step "Continue to Setup Inbox" opens at — the first incomplete one.
     *
     * Clamped rather than trusted: a stored 7, or a 0 from a row written before this column
     * existed, has to land somewhere sensible instead of on a step the wizard cannot render.
     */
    public function setupStep(): int
    {
        return max(self::STEP_NAME, min((int) $this->setup_step, self::LAST_STEP));
    }

    /**
     * Record that a step is done.
     *
     * Only ever moves FORWARD. Somebody who reopens the wizard and walks through step 1 again
     * has not undone steps 2 and 3, and rewinding their progress because they re-read a screen
     * would be the flow punishing them for looking.
     */
    public function advanceSetupTo(int $step): void
    {
        if ($step > $this->setupStep()) {
            $this->forceFill(['setup_step' => min($step, self::LAST_STEP)])->save();
        }
    }

    /**
     * The types as the screen shows them — always a list, never null.
     *
     * A caller that has to branch on "null or array?" before rendering chips is a caller that
     * will forget once.
     *
     * @return array<int, string>
     */
    public function typeLabels(): array
    {
        return array_values(array_filter((array) ($this->types ?? [])));
    }

    /**
     * Tidy a list of typed-in values: trimmed, collapsed, de-duplicated.
     *
     * Lives on the model because two paths write it — the create page and the edit dialog —
     * and "is Partner Support the same as partner support?" has to have one answer. It is:
     * the same, compared case-insensitively, and the FIRST spelling wins, because that is the
     * one the person actually typed.
     *
     * Deliberately does NOT enforce `MAX_TYPES`. Tidying runs before validation, so a cap here
     * would quietly turn eleven values into eight and leave the `types.max` rule unreachable —
     * an over-long list would be accepted silently instead of refused with a reason.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    public static function cleanTypes(array $values): array
    {
        $clean = [];
        $seen = [];

        foreach ($values as $value) {
            $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

            if ($value === '') {
                continue;
            }

            $value = Str::limit($value, self::MAX_TYPE_LENGTH, '');
            $key = Str::lower($value);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $clean[] = $value;
        }

        return $clean;
    }

    /**
     * The letter on the avatar (§5's optional icon).
     *
     * Taken from the name rather than stored, so renaming a space renames its avatar too — a
     * stored initial is a second copy of the first character waiting to disagree with the first.
     */
    public function initial(): string
    {
        return Str::upper(Str::substr(trim((string) $this->name), 0, 1)) ?: '?';
    }
}
