<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A Space's working week (docs/features/helpdesk-sla.md, §5–§6). TENANT-SCOPED.
 *
 * The calendar an SLA policy counts against. This model answers ONE question — "is this instant
 * inside working hours, and if not, when is the next one that is?" — and the engine (S2) builds
 * everything else on top of it. Keeping the week's shape and the arithmetic over it apart is
 * what lets the arithmetic be tested without a database.
 */
class HelpCenterBusinessHours extends Model
{
    use BelongsToTenant;

    protected $table = 'help_center_business_hours';

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'name',
        'timezone',
        'schedule',
        'is_default',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'schedule' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    public function policies(): HasMany
    {
        return $this->hasMany(HelpCenterSlaPolicy::class, 'help_center_business_hours_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        // Default first — it is the one most people are looking for — then alphabetical.
        return $query->orderByDesc('is_default')->orderBy('name');
    }

    /**
     * The open and close times for a day key (`mon` … `sun`), or null when closed.
     *
     * Every unreadable shape returns null, which is CLOSED. A malformed blob must not be read as
     * "open all day": the failure of a promise about working hours should be to promise less,
     * not more.
     *
     * @return array{open: string, close: string}|null
     */
    public function day(string $key): ?array
    {
        $day = ($this->schedule ?? [])[$key] ?? null;

        if (! is_array($day) || ! isset($day['open'], $day['close'])) {
            return null;
        }

        // An open that is not before its close is not a window. Half past nine to half past nine
        // is zero minutes of service, and a close BEFORE its open is somebody's typo — treating
        // either as a working day would hand out negative or infinite time.
        if (strcmp((string) $day['open'], (string) $day['close']) >= 0) {
            return null;
        }

        return ['open' => (string) $day['open'], 'close' => (string) $day['close']];
    }

    /** Is there any working time at all in this week? */
    public function hasOpenDay(): bool
    {
        foreach (array_keys((array) config('help-center.sla_week_days')) as $key) {
            if ($this->day($key) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $days = [];

        foreach ((array) config('help-center.sla_week_days') as $key => $label) {
            $days[] = ['key' => $key, 'label' => $label, 'hours' => $this->day($key)];
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'timezone' => $this->timezone,
            'is_default' => (bool) $this->is_default,
            'days' => $days,
        ];
    }
}
