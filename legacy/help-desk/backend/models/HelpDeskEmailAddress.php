<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A customer-facing address connected to an inbox (Inbound Email requirements §5, §7, §10).
 * TENANT-SCOPED.
 *
 * The address customers write to — support@acme.com — which their mail provider forwards to the
 * inbox's generated inbound address. This row is the record of that arrangement and of whether
 * it is actually working.
 *
 * The important thing about every status here is that ProjectBlock does not control any of it.
 * The forwarding rule lives in somebody else's mail provider, so the only honest evidence that
 * it works is a message arriving. That is why `connected` is set by ingestion and by nothing
 * else, and why `disable()` is bookkeeping rather than an off switch: it stops us counting the
 * address, and mail forwarded to the inbox still arrives, because only they can stop that.
 */
class HelpDeskEmailAddress extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** Connected here, but nothing has been forwarded yet (§7's initial status). */
    public const STATUS_SETUP_REQUIRED = 'setup_required';

    /** Somebody pressed Verify Connection and we are watching for a message (§8). */
    public const STATUS_WAITING = 'waiting_for_email';

    /** A message actually arrived carrying this address. The only proof there is. */
    public const STATUS_CONNECTED = 'connected';

    /** A verification window went by with nothing arriving. */
    public const STATUS_ERROR = 'error';

    /** Switched off here. Their forwarding may well still be running. */
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'tenant_id',
        'help_desk_id',
        'help_desk_inbox_id',
        'address',
        'label',
        'status',
        'verification_started_at',
        'verified_at',
        'last_email_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'verification_started_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_email_at' => 'datetime',
        ];
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(HelpDeskInbox::class, 'help_desk_inbox_id');
    }

    public function helpDesk(): BelongsTo
    {
        return $this->belongsTo(HelpDesk::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What this address is called on screen (Setup Inbox flow, step 2).
     *
     * Falls back to the address itself rather than to a blank cell: an address nobody renamed
     * is not misconfigured, and a table with an empty Name column reads as one that is.
     */
    public function displayName(): string
    {
        return trim((string) $this->label) ?: (string) $this->address;
    }

    public function isDisabled(): bool
    {
        return $this->status === self::STATUS_DISABLED;
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    /**
     * Turn a verification that never resolved into an error, in place.
     *
     * Called on read, with no scheduled sweep behind it — the same approach
     * `WorkspaceInvitation::markExpiredIfLapsed()` takes. A status that is only correct while a
     * worker happens to be running is a status nobody can trust, and "waiting for email" three
     * days later is not waiting, it is broken.
     */
    public function markErrorIfVerificationLapsed(): bool
    {
        if ($this->status !== self::STATUS_WAITING || ! $this->verification_started_at) {
            return false;
        }

        $window = (int) config('help-desk.inbound.verification_window_hours', 24);

        if ($this->verification_started_at->copy()->addHours($window)->isFuture()) {
            return false;
        }

        $this->forceFill(['status' => self::STATUS_ERROR])->save();

        return true;
    }

    /** How the status reads on screen. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_CONNECTED => 'Connected',
            self::STATUS_WAITING => 'Waiting for email',
            self::STATUS_ERROR => 'Error',
            self::STATUS_DISABLED => 'Disabled',
            default => 'Setup required',
        };
    }

    /**
     * What the status means, in a sentence.
     *
     * Written out rather than left to the colour of a badge: every one of these states is
     * something the reader has to do something about, in somebody else's mail provider, and a
     * word on its own does not say what.
     */
    public function statusHint(): string
    {
        return match ($this->status) {
            self::STATUS_CONNECTED => 'Mail forwarded from this address is arriving in this inbox.',
            self::STATUS_WAITING => 'Send a message to this address. It appears here once it reaches us.',
            self::STATUS_ERROR => 'Nothing arrived while we were watching. Check the forwarding rule in your mail provider.',
            self::STATUS_DISABLED => 'Not counted here. Mail forwarded to the inbox still arrives — only your mail provider can stop that.',
            default => 'Forward this address to the inbound address below, then verify the connection.',
        };
    }
}
