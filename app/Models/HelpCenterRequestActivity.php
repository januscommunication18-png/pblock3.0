<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One thing that happened to a Request (docs/features/help-center.md, P36). TENANT-SCOPED.
 *
 * Written by `RequestActivity`, read by four tabs. See the migration for why it is one table.
 */
class HelpCenterRequestActivity extends Model
{
    use BelongsToTenant;

    protected $table = 'help_center_request_activity';

    public const EVENT_CREATED = 'created';

    public const EVENT_ASSIGNED = 'assigned';

    public const EVENT_STATUS = 'status_changed';

    public const EVENT_PRIORITY = 'priority_changed';

    public const EVENT_TAGS = 'tags_changed';

    public const EVENT_SPAM = 'spam_changed';

    public const EVENT_CUSTOMER = 'customer_updated';

    public const EVENT_SNOOZED = 'snoozed';

    public const EVENT_UNSNOOZED = 'unsnoozed';

    public const EVENT_RATING_REQUESTED = 'rating_requested';

    public const EVENT_RATING_SUBMITTED = 'rating_submitted';

    public const EVENT_RATING_CHANGED = 'rating_changed';

    protected $fillable = [
        'tenant_id',
        'help_center_request_id',
        'actor_id',
        'event',
        'field',
        'old_value',
        'new_value',
        'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** A transition is an activity row about the status — not a second table (see the migration). */
    public function isTransition(): bool
    {
        return $this->field === 'status';
    }

    /**
     * The sentence the Activity tab shows.
     *
     * Built here rather than in JavaScript: the phrasing changes with the event, and a client
     * that has to know how to phrase eight events is a second copy of this vocabulary.
     */
    public function sentence(): string
    {
        $old = trim((string) $this->old_value);
        $new = trim((string) $this->new_value);

        return match ($this->event) {
            self::EVENT_CREATED => 'Ticket created',
            self::EVENT_ASSIGNED => $new === ''
                ? 'Unassigned'.($old === '' ? '' : ' from '.$old)
                : ($old === '' ? 'Assigned to '.$new : 'Reassigned from '.$old.' to '.$new),
            self::EVENT_STATUS => 'Status changed',
            self::EVENT_PRIORITY => 'Priority changed',
            self::EVENT_TAGS => 'Tags changed',
            self::EVENT_SPAM => $new === 'yes' ? 'Marked as spam' : 'No longer spam',
            self::EVENT_CUSTOMER => 'Customer details updated',
            /*
             * The requirement's own words for these two, near enough (P45).
             *
             * History renders `sentence()` with the old → new pair beside it, so "Ticket snoozed"
             * lands next to "— → Aug 25, 2026 at 8:00 AM" and reads as the spec's example without
             * the sentence having to repeat the date it is already sitting next to.
             */
            self::EVENT_SNOOZED => ($this->meta['rescheduled'] ?? false) ? 'Snooze rescheduled' : 'Ticket snoozed',
            self::EVENT_UNSNOOZED => match ($this->meta['reason'] ?? null) {
                'customer_reply' => 'Snooze ended — Customer replied',
                'due' => 'Ticket unsnoozed automatically',
                default => 'Ticket unsnoozed',
            },
            // The rating trio (P56). History renders these beside their old → new pair, so the
            // sentence stays short and the scores sit next to it.
            self::EVENT_RATING_REQUESTED => 'Rating request sent',
            self::EVENT_RATING_SUBMITTED => 'Customer rated this ticket',
            self::EVENT_RATING_CHANGED => 'Customer changed their rating',
            default => ucfirst(str_replace('_', ' ', $this->event)),
        };
    }

    /**
     * The Activity feed's sentence, minus the actor — "changed the status to Review".
     *
     * The work item feed reads "<b>Name</b> did something", so the name is rendered separately
     * and this is what follows it. History uses `sentence()` and the old → new pair instead,
     * because a list of changes reads better as a table of values than as prose.
     */
    public function phrase(): string
    {
        $old = trim((string) $this->old_value);
        $new = trim((string) $this->new_value);
        $meta = (array) $this->meta;

        return match ($this->event) {
            self::EVENT_CREATED => ($meta['via'] ?? null) === 'inbound_email'
                ? 'opened this ticket from an inbound email'
                : 'created this ticket',
            self::EVENT_ASSIGNED => match (true) {
                $new === '' => 'unassigned this ticket',
                ($meta['via'] ?? null) === 'default_assignee' => 'assigned it to '.$new.' automatically',
                ($meta['via'] ?? null) === 'workflow_rule' => 'assigned it to '.$new.' by the workflow rule',
                $old === '' => 'assigned it to '.$new,
                default => 'reassigned it from '.$old.' to '.$new,
            },
            self::EVENT_STATUS => 'changed the status to '.($new ?: 'none'),
            self::EVENT_PRIORITY => 'set the priority to '.($new ?: 'none'),
            self::EVENT_TAGS => $new === '' ? 'removed every tag' : 'changed the tags to '.$new,
            self::EVENT_SPAM => $new === 'yes' ? 'marked this as spam' : 'marked this as not spam',
            self::EVENT_CUSTOMER => 'updated the customer details',
            /*
             * The Activity feed reads "<b>Name</b> <phrase>", and a snooze's actor can be nobody
             * — the sweep and a customer's reply both end one with no signed-in user. `toPayload`
             * renders those as "System", so these phrases have to make sense after that word too:
             * "System brought this ticket back — the snooze period ended" does.
             */
            self::EVENT_SNOOZED => 'snoozed this ticket until '.($new ?: 'later')
                .(($this->meta['condition_label'] ?? null) ? ' ('.strtolower($this->meta['condition_label']).')' : ''),
            self::EVENT_UNSNOOZED => match ($this->meta['reason'] ?? null) {
                'customer_reply' => 'brought this ticket back — the customer replied',
                'due' => 'brought this ticket back — the snooze period ended',
                default => 'unsnoozed this ticket',
            },
            /*
             * The rating phrases (P56), worded as the requirement writes them.
             *
             * The actor on all three is NULL — a customer is not a user of this application —
             * so `toPayload` renders "System", and these read as sentences after that word.
             */
            self::EVENT_RATING_REQUESTED => 'sent a rating request to '.($new ?: 'the customer'),
            self::EVENT_RATING_SUBMITTED => 'recorded a customer rating of '.($new ?: '—')
                .(($meta['comment'] ?? false) ? ' with written feedback' : ''),
            self::EVENT_RATING_CHANGED => 'recorded a rating change from '.($old ?: '—').' to '.($new ?: '—'),
            'replied' => 'replied to the customer',
            default => str_replace('_', ' ', $this->event),
        };
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'kind' => 'activity',
            'event' => $this->event,
            'field' => $this->field,
            'title' => $this->sentence(),
            'phrase' => $this->phrase(),
            'old' => $this->old_value,
            'new' => $this->new_value,
            'transition' => $this->isTransition(),
            /*
             * The raw `meta`, for the client to read (P45).
             *
             * Everything else here is a display string, deliberately — see the migration. The one
             * exception is the snooze pair, whose meta carries the moment in ISO so the browser
             * can render it in the AGENT'S timezone. This app formats server-side in UTC, and a
             * snooze is the one record where that is actively wrong: somebody types "10:30am",
             * and a history telling them they set 2:30 PM is a history arguing with them.
             */
            'meta' => $this->meta,
            /*
             * "System" rather than a name, when nobody did it.
             *
             * An inbound email opening a Request, an auto-assignment (P26) and a rule-driven
             * status change all have a null actor, and the timeline should say so plainly
             * instead of attributing them to whoever happened to be looking.
             */
            'actor' => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->displayName(),
                'initial' => mb_strtoupper(mb_substr($this->actor->displayName(), 0, 1)),
                'avatar_url' => $this->actor->avatar_url ?? null,
            ] : null,
            'actor_name' => $this->actor?->displayName() ?? 'System',
            'at' => $this->created_at?->format('M j, Y \a\t g:i A'),
            'ago' => $this->created_at?->diffForHumans(),
            'sort' => $this->created_at?->getTimestamp() ?? 0,
        ];
    }
}
