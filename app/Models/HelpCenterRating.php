<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One rating request, and the rating it may become (P56). TENANT-SCOPED.
 *
 * See the migration for why the request and the response are one row.
 */
class HelpCenterRating extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'help_center_space_id', 'help_center_request_id', 'help_center_customer_id',
        'agent_id', 'token', 'rating_type', 'score', 'raw_score', 'comment',
        'requested_at', 'send_after', 'sent_at', 'submitted_at', 'expires_at',
        'cancelled_at', 'reminders_sent',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'send_after' => 'datetime',
            'sent_at' => 'datetime',
            'submitted_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(HelpCenterRequest::class, 'help_center_request_id');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(HelpCenterCustomer::class, 'help_center_customer_id');
    }

    /**
     * A link nobody can guess.
     *
     * 48 random characters. This is the only thing standing between a stranger and somebody
     * else's feedback form, and it travels by email to an address outside our control.
     */
    public static function newToken(): string
    {
        return Str::random(48);
    }

    public function isAnswered(): bool
    {
        return $this->submitted_at !== null;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * May the customer act on this link right now?
     *
     * Four ways to say no, and the page tells them apart — "expired" and "already answered" are
     * different situations and a single "this link is not valid" would leave somebody who
     * answered last week wondering whether it saved.
     */
    public function isOpen(): bool
    {
        if ($this->isCancelled() || $this->isExpired()) {
            return false;
        }

        // An answered request is still open when the Space allows changes (§17).
        return ! $this->isAnswered() || (bool) $this->space?->ratingSettings?->allow_change;
    }

    /** Requests still waiting to go out — what the sweep sends. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->whereNull('sent_at')
            ->whereNull('cancelled_at')
            ->whereNull('submitted_at')
            ->where('send_after', '<=', now());
    }

    /** Answered — the denominator of every average, and the numerator of the response rate. */
    public function scopeAnswered(Builder $query): Builder
    {
        return $query->whereNotNull('submitted_at');
    }

    /** "★★★★☆" / "👍" / "7 / 10" — the score as the customer chose it, for the ticket view. */
    public function display(): ?string
    {
        if (! $this->isAnswered()) {
            return null;
        }

        $points = (int) (config('help-center.rating_types.'.$this->rating_type.'.points') ?? 5);
        $raw = (int) ($this->raw_score ?? $this->score);

        return match ($this->rating_type) {
            'thumbs' => $raw >= 2 ? '👍' : '👎',
            'scale10' => $raw.' / 10',
            'emoji5' => ['😠', '🙁', '😐', '🙂', '😍'][max(0, min(4, $raw - 1))],
            default => str_repeat('★', $raw).str_repeat('☆', max(0, $points - $raw)),
        };
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $settings = $this->space?->ratingSettings;

        return [
            'id' => $this->id,
            'answered' => $this->isAnswered(),
            'score' => $this->score,
            'raw_score' => $this->raw_score,
            'rating_type' => $this->rating_type,
            'display' => $this->display(),
            'label' => $this->score === null
                ? null
                : ($settings?->label((int) $this->score) ?? (string) $this->score),
            'comment' => $this->comment,
            'agent' => $this->agent?->displayName(),
            'customer' => $this->customer?->displayName() ?? $this->request?->customerLabel(),
            'requested_at' => $this->requested_at?->format('M j, Y'),
            'submitted_at' => $this->submitted_at?->format('M j, Y'),
            'cancelled' => $this->isCancelled(),
            'expired' => $this->isExpired(),
        ];
    }
}
