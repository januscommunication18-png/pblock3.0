<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A Space's CSAT configuration (docs/features/help-center.md, P56). TENANT-SCOPED.
 *
 * A missing row means the packaged defaults — see `for()`. That is what lets every consumer ask
 * a Space for its rating settings without first asking whether it has any.
 */
class HelpCenterRatingSettings extends Model
{
    use BelongsToTenant;

    protected $table = 'help_center_rating_settings';

    /**
     * The column defaults, restated for the UNSAVED instance.
     *
     * `for()` hands back `new self(...)` when a Space has never configured rating, and a model
     * that has not been through the database has none of the migration's defaults — every
     * boolean reads null, which casts to false. That made "Allow customer comment" arrive switched
     * OFF on a page whose stored default is on, and the same would have been true of every other
     * flag as it was added.
     *
     * These must stay in step with the migration. They are the same values written twice, which
     * is a real cost — and the alternative, a settings screen that lies about its own defaults
     * until somebody presses Save, is a worse one.
     */
    protected $attributes = [
        'enabled' => false,
        'rating_type' => 'stars5',
        'allow_comment' => true,
        'comment_requirement' => 'low_only',
        'trigger' => 'resolved',
        'delay_minutes' => 0,
        'reminder_enabled' => false,
        'reminder_after_days' => 2,
        'reminder_max' => 1,
        'expires_days' => 14,
        'allow_change' => false,
        'rerequest_after_reopen' => false,
        'low_threshold' => 2,
        'low_notify_agent' => true,
        'low_notify_admin' => true,
        'low_internal_note' => true,
        'low_reopen' => false,
    ];

    protected $fillable = [
        'tenant_id', 'help_center_space_id', 'enabled',
        'rating_type', 'labels', 'allow_comment', 'comment_requirement',
        'trigger', 'trigger_status_id', 'delay_minutes',
        'reminder_enabled', 'reminder_after_days', 'reminder_max',
        'expires_days', 'allow_change', 'rerequest_after_reopen',
        'low_threshold', 'low_notify_agent', 'low_notify_admin',
        'low_internal_note', 'low_reopen', 'low_tag_id',
    ];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'enabled' => 'boolean',
            'allow_comment' => 'boolean',
            'reminder_enabled' => 'boolean',
            'allow_change' => 'boolean',
            'rerequest_after_reopen' => 'boolean',
            'low_notify_agent' => 'boolean',
            'low_notify_admin' => 'boolean',
            'low_internal_note' => 'boolean',
            'low_reopen' => 'boolean',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    /**
     * The settings in force for a Space — the stored row, or an unsaved one carrying the defaults.
     *
     * Returns a MODEL either way, so callers read `$settings->enabled` without caring whether
     * anybody has configured this Space. The unsaved instance is never persisted by accident:
     * nothing here calls save, and the settings controller uses `updateOrCreate`.
     */
    public static function for(HelpCenterSpace $space): self
    {
        return static::query()->where('help_center_space_id', $space->id)->first()
            ?? new self(['help_center_space_id' => $space->id]);
    }

    /** The five display labels, falling back to the packaged wording for any that is blank. */
    public function labelList(): array
    {
        $stored = (array) ($this->labels ?? []);
        $out = [];

        foreach ((array) config('help-center.rating_labels') as $score => $default) {
            $value = trim((string) ($stored[$score] ?? ''));
            $out[(int) $score] = $value !== '' ? $value : $default;
        }

        return $out;
    }

    public function label(int $score): string
    {
        return $this->labelList()[$score] ?? (string) $score;
    }

    /** The scale's shape, from config. */
    public function type(): array
    {
        return (array) (config('help-center.rating_types.'.$this->rating_type)
            ?? config('help-center.rating_types.stars5'));
    }

    public function points(): int
    {
        return (int) ($this->type()['points'] ?? 5);
    }

    /** A raw choice on this scale as the normalised 1–5 score every report totals. */
    public function normalise(int $raw): int
    {
        $map = (array) ($this->type()['normalise'] ?? []);

        return (int) ($map[$raw] ?? min(5, max(1, $raw)));
    }

    /** Is this score inside the Space's low-rating band (§11)? */
    public function isLow(int $score): bool
    {
        return $score <= (int) $this->low_threshold;
    }

    /**
     * Must the customer write something to submit this score (§10)?
     *
     * `allow_comment` off means the box is not there at all, so nothing can be required —
     * checked first, because a required field that is not rendered is a form nobody can submit.
     */
    public function commentRequired(int $score): bool
    {
        if (! $this->allow_comment) {
            return false;
        }

        return match ($this->comment_requirement) {
            'always' => true,
            'low_only' => $this->isLow($score),
            default => false,
        };
    }

    /** @return array<string, mixed> what the settings screen and the preview both read */
    public function toPayload(): array
    {
        return [
            'enabled' => (bool) $this->enabled,
            'rating_type' => $this->rating_type ?? 'stars5',
            'labels' => $this->labelList(),
            'allow_comment' => (bool) $this->allow_comment,
            'comment_requirement' => $this->comment_requirement ?? 'low_only',
            'trigger' => $this->trigger ?? 'resolved',
            'trigger_status_id' => $this->trigger_status_id,
            'delay_minutes' => (int) $this->delay_minutes,
            'reminder_enabled' => (bool) $this->reminder_enabled,
            'reminder_after_days' => (int) ($this->reminder_after_days ?? 2),
            'reminder_max' => (int) ($this->reminder_max ?? 1),
            'expires_days' => $this->expires_days,
            'allow_change' => (bool) $this->allow_change,
            'rerequest_after_reopen' => (bool) $this->rerequest_after_reopen,
            'low_threshold' => (int) ($this->low_threshold ?? 2),
            'low_notify_agent' => (bool) $this->low_notify_agent,
            'low_notify_admin' => (bool) $this->low_notify_admin,
            'low_internal_note' => (bool) $this->low_internal_note,
            'low_reopen' => (bool) $this->low_reopen,
            'low_tag_id' => $this->low_tag_id,
        ];
    }
}
