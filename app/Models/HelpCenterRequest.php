<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A support REQUEST — the Help Center's primary object (docs/features/help-center.md, P9).
 * TENANT-SCOPED.
 *
 * One inbound email creates one Request. It carries a human-readable **Ticket Number**, it is
 * worked through its Space's **Inbox**, and its status comes entirely from the **Workflow
 * assigned to its Space** — there is no fixed Help Center status list anywhere in this codebase,
 * which is what lets one Space run `New / Investigating / Completed` and another run
 * `Open / Tier 1 / Tier 2 / Resolved` with no code between them.
 *
 * This was `HelpCenterConversation`. The rename is not cosmetic: "conversation", "case", "email"
 * and "ticket" were all in use for this one row, and the product terminology now has a single
 * word for it (P9, Terminology).
 */
class HelpCenterRequest extends Model
{
    use BelongsToTenant;

    /** Waiting-period responsibility, mirrored from the status the Request currently holds. */
    public const WAITING_AGENT = 'agent';

    public const WAITING_CUSTOMER = 'customer';

    public const WAITING_NEITHER = 'neither';

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'help_center_inbox_id',
        'help_center_status_id',
        'ticket_number',
        'assignee_id',
        'subject',
        'preview',
        'priority',
        'customer_email',
        'customer_name',
        'help_center_customer_id',
        'help_center_company_id',
        // The parsed source values this Request arrived with (P75 §8) — what the mappings read,
        // kept so Reprocess has something to re-run over and so an audit can see what was said.
        'inbound_metadata',
        'thread_key',
        'last_message_at',
        'waiting_since',
        'last_activity_at',
        'is_spam',
        'closed_at',
        'snoozed_until',
        'snooze_condition',
        'snoozed_by_id',
        'snoozed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'waiting_since' => 'datetime',
            'last_activity_at' => 'datetime',
            'closed_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'snoozed_at' => 'datetime',
            'is_spam' => 'boolean',
            'inbound_metadata' => 'array',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(HelpCenterInbox::class, 'help_center_inbox_id');
    }

    /**
     * The ticket-specific tag that goes in the outgoing Reply-To (P62).
     *
     * `r<id>-<hmac>`, plus-addressed onto the Space's inbound address:
     *
     *     inbox-8pb4kxdj+r42-9f1c3a7e02@inbound.myprojectblock.dev
     *
     * The point is routing that survives a mail client stripping `References`. Threading headers
     * are the polite way to identify a thread and they are not reliable: Outlook rewrites them,
     * some mobile clients drop them, and a customer who forwards the mail to a colleague loses
     * them entirely. The address itself cannot be lost — replying to it is the one thing the
     * customer is certain to do.
     *
     * SIGNED, not sequential. A bare `r42` would let anybody post into ticket 42 by writing to a
     * guessed address; the HMAC makes the tag unforgeable without the app key. It is not a
     * secret — it travels in every reply — but it cannot be *derived*, which is what matters.
     */
    public function replyTag(): string
    {
        return 'r'.$this->id.'-'.self::replySignature((int) $this->id);
    }

    /**
     * The Request id inside a reply tag, or null if it is not one of ours.
     *
     * `hash_equals` rather than `===`: this compares a signature against attacker-supplied input,
     * and a timing-safe comparison is the standard courtesy even where the practical risk is low.
     */
    public static function fromReplyTag(string $tag): ?int
    {
        if (! preg_match('/^r(\d+)-([a-f0-9]{10})$/', mb_strtolower(trim($tag)), $m)) {
            return null;
        }

        $id = (int) $m[1];

        return hash_equals(self::replySignature($id), $m[2]) ? $id : null;
    }

    private static function replySignature(int $id): string
    {
        return substr(hash_hmac('sha256', 'hc-reply:'.$id, (string) config('app.key')), 0, 10);
    }

    /** Every rating request raised for this ticket (P56), newest last. */
    public function ratings(): HasMany
    {
        return $this->hasMany(HelpCenterRating::class, 'help_center_request_id');
    }

    /** Who put it to sleep (P45). Null when they have since been deleted. */
    public function snoozedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'snoozed_by_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(HelpCenterStatus::class, 'help_center_status_id');
    }

    /**
     * The tags this Request carries (P28).
     *
     * `withoutGlobalScopes` is NOT needed here: the tag rows are reached through the pivot, and
     * every path that reads them already runs inside a tenancy context.
     */
    /** Everything that has happened to this Request, oldest first (P36). */
    public function activity(): HasMany
    {
        return $this->hasMany(HelpCenterRequestActivity::class, 'help_center_request_id')
            ->orderBy('created_at')->orderBy('id');
    }

    /** Internal notes, oldest first (P42). */
    public function notes(): HasMany
    {
        return $this->hasMany(HelpCenterRequestNote::class, 'help_center_request_id')
            ->orderBy('created_at')->orderBy('id');
    }

    /** Internal updates, oldest first (P36). */
    public function updates(): HasMany
    {
        return $this->hasMany(HelpCenterRequestUpdate::class, 'help_center_request_id')
            ->orderBy('created_at')->orderBy('id');
    }

    /** The person who wrote in (P33) — richer than the two columns beside it. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(HelpCenterCustomer::class, 'help_center_customer_id');
    }

    /** The organisation they wrote in from (P75 §9), once one has been matched or created. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(HelpCenterCompany::class, 'help_center_company_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            HelpCenterTag::class,
            'help_center_request_tag',
            'help_center_request_id',
            'help_center_tag_id',
        )->withPivot('tenant_id')->withTimestamps()->orderBy('name');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(HelpCenterMessage::class, 'help_center_request_id')
            ->orderBy('received_at')->orderBy('id');
    }

    // ---- Ticket numbers ------------------------------------------------------------------

    /** The Ticket Number as a customer sees it: `#000011`. */
    public function ticketNumber(): string
    {
        return '#'.str_pad((string) ($this->ticket_number ?: 0), 6, '0', STR_PAD_LEFT);
    }

    /**
     * The next Ticket Number for a workspace.
     *
     * `MAX + 1` under a row lock rather than a counter column or a UUID: two inbound emails
     * arriving together must not both be told they are #11, and the unique index on
     * (tenant_id, ticket_number) means the failure mode if this ever raced is a rejected insert
     * rather than two Requests wearing the same number in front of a customer.
     *
     * Per tenant, so a workspace's numbering starts at 1 and does not disclose anyone else's
     * volume.
     */
    public static function nextTicketNumber(string $tenantId): int
    {
        $max = DB::table('help_center_requests')
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->max('ticket_number');

        return ((int) $max) + 1;
    }

    // ---- Waiting period ------------------------------------------------------------------

    /**
     * Who this Request is waiting on, taken from the status it holds.
     *
     * Read through the status rather than stored on the Request: the answer is a property of the
     * workflow step, and copying it here would let the two disagree the moment a team edited
     * "Waiting on Customer" to stop the agent clock.
     */
    public function waitingOn(): string
    {
        return $this->status?->waiting_on ?: self::WAITING_NEITHER;
    }

    /** Is anybody's clock running? */
    public function isWaiting(): bool
    {
        return $this->waitingOn() !== self::WAITING_NEITHER && $this->waiting_since !== null;
    }

    /**
     * How long the current wait has run, in whole minutes.
     *
     * From `waiting_since`, NOT from `created_at` — the requirement is explicit that this is not
     * the age of the ticket. A Request opened on Monday, answered on Monday and replied to by the
     * customer this morning has been waiting on an agent since this morning, and an Inbox sorted
     * by a three-day age would put it in exactly the wrong place.
     */
    public function waitingMinutes(): ?int
    {
        if (! $this->isWaiting()) {
            return null;
        }

        return (int) $this->waiting_since->diffInMinutes(now());
    }

    /** "27 min", "3 h 10 m", "2 d" — short enough for a grid cell. */
    public function waitingLabel(): ?string
    {
        $minutes = $this->waitingMinutes();

        if ($minutes === null) {
            return null;
        }

        if ($minutes < 60) {
            return $minutes.' min';
        }

        if ($minutes < 1440) {
            $h = intdiv($minutes, 60);

            return $h.' h '.($minutes % 60).' m';
        }

        return intdiv($minutes, 1440).' d';
    }

    /**
     * Move the Request to a status and reset the clocks that implies.
     *
     * The one place a status change happens, because a status change is never only a status
     * change: it hands the wait to somebody else, it is activity, and reaching a status with no
     * `waiting_on` stops the clock rather than resetting it. Three screens each remembering that
     * would be three places for it to be forgotten once.
     */
    public function moveTo(HelpCenterStatus $status): void
    {
        $now = now();
        $wasWaitingOn = $this->waitingOn();

        $this->help_center_status_id = $status->id;
        $this->setRelation('status', $status);

        $this->waiting_since = $status->waiting_on === self::WAITING_NEITHER
            ? null
            // Only restart the clock when responsibility actually CHANGED HANDS. Agent → Agent
            // (say, Open → Escalated) is the same person still owing the same reply, and zeroing
            // it there would hide a ticket that has been ignored all afternoon.
            : ($wasWaitingOn === $status->waiting_on && $this->waiting_since !== null
                ? $this->waiting_since
                : $now);

        $this->last_activity_at = $now;

        // Closed is the workflow's own end, so the Request's closed_at follows the workflow
        // rather than a separate act of closing.
        $this->closed_at = $status->isClosed() ? ($this->closed_at ?? $now) : null;

        $this->save();
    }

    /** Record activity that is not a status change (a reply, an assignment). */
    public function touchActivity(): void
    {
        $this->forceFill(['last_activity_at' => now()])->save();
    }

    /** The customer, as they wrote — name when we have one, address otherwise. */
    public function customerLabel(): string
    {
        return $this->customer_name ?: $this->customer_email;
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    // ---- Query scopes --------------------------------------------------------------------

    public function scopeForSpace(Builder $query, int $spaceId): Builder
    {
        return $query->where('help_center_space_id', $spaceId);
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assignee_id')->whereNull('closed_at')->where('is_spam', false);
    }

    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('assignee_id', $userId)->whereNull('closed_at')->where('is_spam', false);
    }

    public function scopeAssigned(Builder $query): Builder
    {
        return $query->whereNotNull('assignee_id')->whereNull('closed_at')->where('is_spam', false);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereNotNull('closed_at')->where('is_spam', false);
    }

    public function scopeSpam(Builder $query): Builder
    {
        return $query->where('is_spam', true);
    }

    /**
     * Snoozed RIGHT NOW (P45) — not merely "has a snooze column set".
     *
     * The predicate is `snoozed_until > now`, never `snoozed_until IS NOT NULL`, and the
     * difference is what makes the queues correct when the sweep has not run. The scheduled
     * command exists to write the activity row and clear the columns; if it is late, or the
     * scheduler is not running at all, a ticket whose time has passed is still due — and every
     * screen shows it back in its queue on its own.
     *
     * A queue that depends on a cron job to be truthful is a queue that lies every time the cron
     * job misses a beat.
     */
    public function scopeSnoozed(Builder $query): Builder
    {
        return $query->whereNotNull('snoozed_until')
            ->where('snoozed_until', '>', now())
            ->whereNull('closed_at')
            ->where('is_spam', false);
    }

    /** The other side of it — everything the active queues may show. */
    public function scopeNotSnoozed(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('snoozed_until')
            ->orWhere('snoozed_until', '<=', now()));
    }

    /** Is this Request asleep at this moment? The loaded-model reading of `scopeSnoozed`. */
    public function isSnoozed(): bool
    {
        return $this->snoozed_until !== null
            && $this->snoozed_until->isFuture()
            && ! $this->isClosed()
            && ! $this->is_spam;
    }

    /**
     * "Snoozed until Aug 23, 8:00 AM" — the indicator the requirement asks for, worded once.
     *
     * Null when it is not snoozed, so a caller can render the chip or not on the value alone
     * rather than asking two questions and risking one of them.
     */
    public function snoozeLabel(): ?string
    {
        if (! $this->isSnoozed()) {
            return null;
        }

        return 'Snoozed until '.$this->snoozed_until->format('M j, g:i A');
    }

    /**
     * One row of the Inbox, in the work item grid's shape (P9, Inbox List Columns).
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $assignee = $this->relationLoaded('assignee') ? $this->assignee : null;

        return [
            'id' => $this->id,
            // The grid's ID column IS the ticket number — the internal id never reaches a screen.
            'identifier' => $this->ticketNumber(),
            'subject' => $this->subject ?: '(no subject)',
            'preview' => $this->preview,
            'customer' => $this->customerLabel(),
            'customer_email' => $this->customer_email,
            'customer_avatar' => [
                'name' => $this->customerLabel(),
                'initial' => mb_strtoupper(mb_substr($this->customerLabel(), 0, 1)),
                // Customers are not users, so there is no uploaded photo to show — the initial on
                // a colour derived from their address is the honest most we have.
                'avatar_url' => null,
            ],
            'status' => $this->relationLoaded('status') && $this->status
                ? ['id' => $this->status->id, 'name' => $this->status->name, 'color' => $this->status->color]
                : null,
            'status_id' => $this->help_center_status_id,
            'priority' => $this->priority,
            'waiting_on' => $this->waitingOn(),
            'waiting_label' => $this->waitingLabel(),
            'waiting_minutes' => $this->waitingMinutes(),
            'assignee' => $assignee ? [
                'id' => $assignee->id,
                // `displayName()`, not `name`: a user whose name column is empty rendered as a
                // bare "?" avatar with no text beside it once the assignee became a CHIP (P28).
                'name' => $assignee->displayName(),
                'initial' => mb_strtoupper(mb_substr((string) $assignee->displayName(), 0, 1)),
                'avatar_url' => $assignee->avatar_url ?? null,
            ] : null,
            'updated_at' => ($this->last_activity_at ?? $this->last_message_at)?->diffForHumans(null, true),
            'updated_at_iso' => ($this->last_activity_at ?? $this->last_message_at)?->toIso8601String(),
            'closed' => $this->isClosed(),
            'is_spam' => $this->is_spam,

            // Snooze (P45). `snoozed` is the state; the rest is what the chip and the dialog
            // need in order to say what the state is without asking again.
            'snoozed' => $this->isSnoozed(),
            'snooze_label' => $this->snoozeLabel(),
            'snoozed_until_iso' => $this->snoozed_until?->toIso8601String(),
            'snoozed_until_at' => $this->snoozed_until?->format('M j, Y \a\t g:i A'),
            'snooze_condition' => $this->snooze_condition,
            'snooze_condition_label' => $this->snooze_condition === null
                ? null
                : (config('help-center.snooze_conditions.'.$this->snooze_condition.'.label') ?? $this->snooze_condition),
            'snoozed_by' => $this->relationLoaded('snoozedBy') && $this->snoozedBy
                ? $this->snoozedBy->displayName()
                : null,
            /*
             * Only when LOADED (P28).
             *
             * `relationLoaded` rather than `$this->tags`: `toPayload()` is called once per row of
             * a queue, and a lazy read here would be one query per Request. The screens that show
             * tag chips eager-load them; the ones that do not get an empty list and draw no chip,
             * which is honest — they were not asked for.
             */
            'tags' => $this->relationLoaded('tags')
                ? $this->tags->map(fn (HelpCenterTag $t) => ['id' => $t->id, 'name' => $t->name])->values()->all()
                : [],
        ];
    }
}
